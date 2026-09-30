<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\ChampionshipDocument;
use App\Models\MobileAccessToken;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournaments\ChampionshipDocuments;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChampionshipDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private User $org;

    private Championship $champ;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Organization', 'Secretary', 'Coach', 'Student', 'Judge'] as $role) {
            $id = DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $role]);
        }
        $this->org = $this->user('Organization');
        $this->champ = Championship::create(['name' => 'Championship', 'organization_id' => $this->org->id, 'banner' => 'test.jpg']);
        $this->acceptMobileAgreements($this->org);
        $this->actingAs($this->org);
    }

    private function user(string $role, array $extra = []): User
    {
        return User::forceCreate($extra + ['first_name' => 'Test', 'last_name' => 'User', 'email' => uniqid().'@example.test', 'password' => 'test', 'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
    }

    private function base(): string
    {
        return '/api/panel/tournaments/'.$this->champ->id.'/documents';
    }

    private function upload(): ChampionshipDocument
    {
        $id = $this->postJson($this->base(), ['name' => 'Regulation', 'file' => UploadedFile::fake()->create('rules.pdf', 1, 'application/pdf')])->assertCreated()->json('item.id');

        return ChampionshipDocument::findOrFail($id);
    }

    private function tournament(array $extra = []): Tournament
    {
        return Tournament::forceCreate($extra + ['name' => 'T', 'championship_id' => $this->champ->id, 'organization_id' => $this->org->id,
            'age_from' => 0, 'age_to' => 99, 'tournament_type' => Tournament::KUMITE, 'tatami' => 1, 'price' => 0, 'date_commission' => today(), 'date' => today(), 'date_finish' => today(), 'address' => 'City']);
    }

    public function test_owner_and_secretary_upload_rename_replace_delete_and_log(): void
    {
        $document = $this->upload();
        Storage::disk('protected')->assertExists($document->path);
        $this->getJson($this->base())->assertJsonPath('can_manage', true)->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.path');
        $this->get($this->base().'/'.$document->id.'/file')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($this->base().'/'.$document->id.'/file?download=1')->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=document.pdf');
        $this->get('/storage/'.$document->path)->assertNotFound();
        $secretary = $this->user('Secretary', ['organization_id' => $this->org->id]);
        $this->acceptMobileAgreements($secretary);
        $this->actingAs($secretary)->putJson($this->base().'/'.$document->id, ['name' => 'New title'])->assertOk();
        $this->assertSame($document->path, $document->fresh()->path);
        $this->postJson($this->base().'/'.$document->id, ['_method' => 'PUT', 'name' => 'New file', 'file' => UploadedFile::fake()->create('new.pdf', 1, 'application/pdf')])->assertOk();
        Storage::disk('protected')->assertMissing($document->path);
        $document->refresh();
        $this->deleteJson($this->base().'/'.$document->id)->assertOk();
        Storage::disk('protected')->assertMissing($document->path);
        $this->assertDatabaseHas('activity_log', ['event' => 'championship.document.deleted', 'causer_id' => $secretary->id]);
        $this->get($this->base().'/'.$document->id.'/file')->assertNotFound();
    }

    public function test_denied_mutations_never_write_files_or_audit_and_foreign_organization_cannot_read(): void
    {
        $document = $this->upload();
        foreach ([$this->user('Organization'), $this->user('Secretary'), $this->user('Coach'), $this->user('Student'), $this->user('Judge')] as $user) {
            $this->acceptMobileAgreements($user);
            $this->actingAs($user);
            $audit = DB::table('activity_log')->count();
            $files = Storage::disk('protected')->allFiles();
            $this->postJson($this->base(), ['name' => 'Denied', 'file' => UploadedFile::fake()->image('x.png')])->assertForbidden();
            $this->putJson($this->base().'/'.$document->id, ['name' => 'Denied'])->assertForbidden();
            $this->deleteJson($this->base().'/'.$document->id)->assertForbidden();
            $this->assertSame($audit, DB::table('activity_log')->count());
            $this->assertSame($files, Storage::disk('protected')->allFiles());
            if (! $user->hasProjectRole('Coach')) {
                $this->getJson($this->base())->assertForbidden();
                $this->getJson($this->base().'/'.$document->id.'/file')->assertForbidden();
            }
        }
    }

    public function test_mobile_coach_student_boundaries_signed_open_expiry_and_revocation(): void
    {
        $document = $this->upload();
        $coach = $this->user('Coach', ['organization_id' => $this->org->id]);
        $student = $this->user('Student', ['coach_id' => $coach->id]);
        $tournament = $this->tournament();
        DB::table('tournament_treners')->insert(['tournament_id' => $tournament->id, 'trener_id' => $coach->id]);
        $base = '/api/mobile/championships/'.$this->champ->id.'/documents';
        foreach ([$coach, $student] as $user) {
            $this->acceptMobileAgreements($user);
            MobileAccessToken::create(['user_id' => $user->id, 'name' => 'test', 'token' => hash('sha256', 'token'.$user->id), 'expires_at' => now()->addDay()]);
            $this->withToken('token'.$user->id);
            $this->getJson($base)->assertOk()->assertJsonPath('can_manage', false);
            $this->get($base.'/'.$document->id.'/file')->assertOk();
            $link = $this->getJson($base.'/'.$document->id.'/link')->assertOk()->json('url');
            $this->assertStringNotContainsString('token', $link);
            $this->get($link)->assertOk();
        }
        $student->forceFill(['coach_id' => null])->save();
        $this->getJson($base)->assertForbidden();
        $this->get($link)->assertForbidden();
        $student->forceFill(['coach_id' => $coach->id])->save();
        $this->travel(3)->minutes();
        $this->get($link)->assertForbidden();
    }

    public function test_pagination_no_total_limit_and_cross_championship_id(): void
    {
        for ($i = 0; $i < 25; $i++) {
            ChampionshipDocument::create(['championship_id' => $this->champ->id, 'name' => 'Doc '.$i, 'file_name' => 'file.pdf', 'path' => 'test/'.$i, 'disk' => 'protected']);
        }
        $this->getJson($this->base().'?page=2')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('meta.total', 25);
        $other = Championship::create(['name' => 'Other', 'organization_id' => $this->org->id, 'banner' => 'test.jpg']);
        $document = ChampionshipDocument::first();
        $url = '/api/panel/tournaments/'.$other->id.'/documents/'.$document->id;
        $this->get($url.'/file')->assertNotFound();
        $this->deleteJson($url)->assertNotFound();
        $this->assertDatabaseCount('championship_documents', 25);
    }

    public function test_validation_and_failed_database_save_preserve_previous_file(): void
    {
        $document = $this->upload();
        $this->postJson($this->base(), ['name' => '', 'file' => UploadedFile::fake()->create('x.html', 1, 'text/html')])->assertUnprocessable();
        $this->postJson($this->base(), ['name' => 'Too big', 'file' => UploadedFile::fake()->create('x.pdf', 20481, 'application/pdf')])->assertUnprocessable();
        $files = Storage::disk('protected')->allFiles();
        ChampionshipDocument::saving(fn () => throw new \RuntimeException('test rollback'));
        try {
            app(ChampionshipDocuments::class)->save($this->org, $this->champ, 'Replacement', UploadedFile::fake()->create('new.pdf', 1, 'application/pdf'), $document);
            $this->fail('Expected rollback');
        } catch (\RuntimeException $error) {
            $this->assertSame('test rollback', $error->getMessage());
        } finally {
            ChampionshipDocument::flushEventListeners();
        }
        $this->assertSame('Regulation', $document->fresh()->name);
        $this->assertSame($files, Storage::disk('protected')->allFiles());
    }

    public function test_migration_preserves_and_deduplicates_legacy_files_without_storage_io(): void
    {
        $tournament = $this->tournament(['regulation_document' => 'regulation/same.pdf', 'application_document' => 'forms/entry.pdf']);
        $copy = $tournament->replicate();
        $copy->save();
        $migration = require database_path('migrations/2026_09_30_150000_create_championship_documents_table.php');
        $migration->down();
        $migration->up();
        $this->assertDatabaseCount('championship_documents', 2);
        $this->assertSame('regulation/same.pdf', $tournament->fresh()->regulation_document);
        $this->assertDatabaseHas('championship_documents', ['disk' => 'public', 'path' => 'forms/entry.pdf']);
    }
}
