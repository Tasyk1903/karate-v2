<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\Examination;
use App\Models\ExternalForm;
use App\Models\MobileAccessToken;
use App\Models\StudentTournament;
use App\Models\Tournament;
use App\Models\User;
use App\Services\ProtectedMedia;
use App\Services\Tournaments\ExternalFormImportService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class PanelAccessSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Storage::fake('public');
        Storage::fake('protected');
        foreach (['Organization', 'Secretary', 'Coach', 'Judge', 'Student', 'Admin'] as $name) {
            $id = DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $name]);
        }
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function user(string $role, ?User $organization = null, array $attributes = []): User
    {
        $roleId = DB::table('roles')->where('name', $role)->value('id')
            ?? DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web']);

        return User::query()->create(array_merge([
            'first_name' => 'First', 'last_name' => 'Last', 'email' => Str::uuid().'@example.test',
            'password' => 'password', 'role_id' => $roleId, 'organization_id' => $organization?->id,
        ], $attributes));
    }

    public function test_only_organization_can_read_export_create_and_update_privileged_team_sections(): void
    {
        $organization = $this->user('Organization');
        $foreignOrganization = $this->user('Organization');
        foreach (['judges' => 'Judge', 'secretaries' => 'Secretary'] as $section => $role) {
            $member = $this->user($role, $organization);
            foreach (['Secretary', 'Coach', 'Judge', 'Student'] as $actorRole) {
                $actor = $this->user($actorRole, $organization);
                $this->actingAs($actor)->getJson('/api/panel/team?section='.$section)->assertForbidden();
                $this->getJson('/api/panel/team/export?section='.$section)->assertForbidden();
                $this->postJson('/api/panel/team/'.$section, [])->assertForbidden();
                $before = $member->getRawOriginal('password');
                $this->putJson('/api/panel/team/'.$section.'/'.$member->id, ['password' => 'changed-secret'])->assertForbidden();
                $this->assertSame($before, $member->refresh()->getRawOriginal('password'));
            }
            $this->actingAs($foreignOrganization)->putJson('/api/panel/team/'.$section.'/'.$member->id, [])->assertForbidden();
            $this->actingAs($organization)->getJson('/api/panel/team?section='.$section)->assertOk();
            $this->postJson('/api/panel/team/'.$section, [])->assertUnprocessable();
            $this->putJson('/api/panel/team/'.$section.'/'.$member->id, [])->assertUnprocessable();
        }
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_organization_can_manage_and_export_its_privileged_accounts(): void
    {
        $organization = $this->user('Organization');
        $this->actingAs($organization);
        foreach (['judges' => 'Judge', 'secretaries' => 'Secretary'] as $section => $role) {
            $data = ['first_name' => 'First', 'last_name' => 'Created',
                'email' => $section.'@example.test', 'password' => 'created-password',
                'judge_position' => 'judge1_score'];
            $response = $this->postJson('/api/panel/team/'.$section, $data)->assertCreated();
            $member = User::findOrFail($response->json('item.id'));
            $this->assertTrue($member->hasProjectRole($role));
            $this->assertEquals($organization->id, $member->organization_id);
            $data['first_name'] = 'Updated';
            $this->putJson('/api/panel/team/'.$section.'/'.$member->id, $data)->assertOk();
            $this->assertSame('Updated', $member->refresh()->first_name);
            $this->getJson('/api/panel/team/export?section='.$section)->assertStatus(202);
        }
        $this->assertDatabaseCount('activity_log', 6);
    }

    public function test_secretary_capabilities_and_stats_do_not_expose_privileged_sections(): void
    {
        $organization = $this->user('Organization');
        $secretary = $this->user('Secretary', $organization);
        $this->actingAs($secretary)->getJson('/api/auth/user')
            ->assertJsonPath('user.capabilities.team_sections', ['trainers', 'students', 'pending'])
            ->assertJsonPath('user.capabilities.view_examinations', false);
        $response = $this->getJson('/api/panel/team?section=trainers')->assertOk();
        $this->assertArrayNotHasKey('judges', $response->json('stats'));
        $this->assertArrayNotHasKey('secretaries', $response->json('stats'));
    }

    public function test_secretary_has_no_examination_routes_including_direct_exports_and_mutations(): void
    {
        $organization = $this->user('Organization');
        $secretary = $this->user('Secretary', $organization);
        $student = $this->user('Student', $organization);
        $exam = Examination::query()->create(['organization_id' => $organization->id, 'name' => 'Exam',
            'city' => 'City', 'date' => '2026-10-01', 'receiving' => 'Examiner']);
        $base = '/api/panel/examinations';
        $this->actingAs($secretary);
        foreach ([$base, "$base/$exam->id", "$base/$exam->id/students", "$base/$exam->id/students/export", "$base/$exam->id/attach-options"] as $url) {
            $this->getJson($url)->assertForbidden();
        }
        $this->postJson($base, [])->assertForbidden();
        $this->putJson("$base/$exam->id", [])->assertForbidden();
        $this->deleteJson("$base/$exam->id")->assertForbidden();
        $this->postJson("$base/$exam->id/students", ['student_ids' => [$student->id]])->assertForbidden();
        $this->postJson("$base/$exam->id/attach-self")->assertForbidden();
        $this->deleteJson("$base/$exam->id/students/$student->id")->assertForbidden();
        $this->assertDatabaseCount('examinations', 1);
        $this->assertDatabaseCount('examination_student', 0);
        $this->actingAs($organization)->getJson("$base/$exam->id")->assertOk();
        $this->putJson("$base/$exam->id", ['name' => 'Updated', 'city' => 'City', 'date' => '2026-10-02', 'receiving' => 'Examiner'])->assertOk();
        $this->actingAs($this->user('Coach', $organization))->getJson("$base/$exam->id")->assertOk();
        $this->putJson("$base/$exam->id", [])->assertForbidden();
    }

    private function form(User $organization): ExternalForm
    {
        $championship = Championship::query()->create(['name' => 'Championship', 'banner' => 'banner.jpg', 'organization_id' => $organization->id]);

        return ExternalForm::query()->create(['championship_id' => $championship->id, 'organization_name' => 'External team',
            'token' => Str::random(40), 'status' => 'open', 'data' => []]);
    }

    public function test_public_user_id_and_global_name_match_never_update_an_existing_account(): void
    {
        $organization = $this->user('Organization');
        $foreign = $this->user('Organization');
        $victim = $this->user('Student', $foreign, ['birthday' => '2015-01-01', 'weight' => 40]);
        $form = $this->form($organization);
        $this->putJson('/api/external-form/'.$form->token, ['participants' => [[
            'user_id' => $victim->id, 'first_name' => $victim->first_name, 'last_name' => $victim->last_name,
            'birthday' => '01.01.2015', 'weight' => 99,
        ]]])->assertOk()->assertJsonMissingPath('participants.0.user_id');
        $form->refresh()->update(['status' => 'closed']);
        $import = app(ExternalFormImportService::class);
        $result = $import->import($form->refresh(), $organization);
        $this->assertSame(1, $result['created_users']);
        $this->assertEquals(40, $victim->refresh()->weight);
        $linked = DB::table('external_form_students')->where('external_form_id', $form->id)->value('user_id');
        $this->assertNotEquals($victim->id, $linked);
        $this->assertEquals($organization->id, User::findOrFail($linked)->organization_id);
        $this->assertSame(0, $import->import($form->refresh(), $organization)['created_users']);
        $this->getJson('/api/external-form/'.$form->token)->assertJsonMissingPath('form.participants.0.user_id');
    }

    public function test_legacy_public_ids_and_foreign_row_ids_are_not_trusted(): void
    {
        $organization = $this->user('Organization');
        $victim = $this->user('Secretary', $organization);
        $form = $this->form($organization);
        $form->update(['status' => 'closed', 'data' => ['participants' => [
            ['user_id' => $victim->id, 'first_name' => 'Attacker', 'last_name' => 'Input'],
        ]]]);
        app(ExternalFormImportService::class)->import($form, $organization);
        $this->assertSame('First', $victim->refresh()->first_name);
        $row = $form->refresh()->data['participants'][0];
        $otherForm = $this->form($organization);
        $response = $this->putJson('/api/external-form/'.$otherForm->token, ['participants' => [$row]])->assertOk();
        $this->assertNotSame($row['row_id'], $response->json('participants.0.row_id'));
    }

    public function test_import_fails_without_changes_when_linked_student_leaves_organization(): void
    {
        $organization = $this->user('Organization');
        $form = $this->form($organization);
        $importer = app(ExternalFormImportService::class);
        $importer->saveRows($form, [['first_name' => 'Student', 'last_name' => 'Original']]);
        $form->refresh()->update(['status' => 'closed']);
        $importer->import($form->refresh(), $organization);
        $userId = DB::table('external_form_students')->value('user_id');
        User::findOrFail($userId)->forceFill(['organization_id' => $this->user('Organization')->id])->save();
        $url = '/api/panel/tournaments/'.$form->championship_id.'/forms/'.$form->id.'/import';
        $count = DB::table('activity_log')->count();
        $this->actingAs($organization)->postJson($url)->assertForbidden();
        $this->assertEquals($count, DB::table('activity_log')->count());
        $this->assertDatabaseCount('external_form_students', 1);
    }

    public function test_documents_require_ownership_and_are_never_available_via_storage(): void
    {
        $organization = $this->user('Organization');
        $coach = $this->user('Coach', $organization);
        $otherCoach = $this->user('Coach', $organization);
        $student = $this->user('Student', $organization, ['coach_id' => $coach->id, 'passport' => 'legacy/sensitive.png']);
        Storage::disk('public')->put('legacy/sensitive.png', 'private-document');
        Storage::disk('public')->put('avatar/photo.png', 'public-avatar');
        $url = "/api/panel/files/users/$student->id/passport";
        $this->getJson('/storage/legacy/sensitive.png')->assertNotFound();
        $this->getJson($url)->assertUnauthorized();
        foreach ([$otherCoach, $this->user('Judge', $organization), $this->user('Organization')] as $denied) {
            $this->actingAs($denied)->get($url)->assertForbidden();
        }
        foreach ([$coach, $organization, $this->user('Secretary', $organization), $student] as $allowed) {
            $this->actingAs($allowed)->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        }
        $this->get('/storage/avatar/photo.png')->assertOk();
        $this->get('/storage/passport/orphan.png')->assertNotFound();
        $this->get("/api/panel/files/users/$student->id/password")->assertNotFound();
        $media = app(ProtectedMedia::class);
        foreach (['../secret', 'passport/../../secret', '/etc/passwd', 'a\\b', "a\0b", 'legacy//sensitive.png'] as $path) {
            $this->assertFalse($media->validPath($path));
        }
    }

    public function test_mobile_documents_use_bearer_access_not_public_urls(): void
    {
        $organization = $this->user('Organization');
        $coach = $this->user('Coach', $organization);
        $student = $this->user('Student', $organization, ['coach_id' => $coach->id, 'passport' => 'passport/mobile.png']);
        Storage::disk('protected')->put('passport/mobile.png', 'private');
        $this->acceptMobileAgreements($coach);
        MobileAccessToken::query()->create(['user_id' => $coach->id, 'name' => 'test', 'token' => hash('sha256', 'secret'), 'expires_at' => now()->addHour()]);
        $url = "/api/mobile/files/users/$student->id/passport";
        $this->getJson($url)->assertUnauthorized();
        $this->withToken('secret')->get($url)->assertOk();
        $this->withToken('invalid')->getJson($url)->assertUnauthorized();
        $this->assertStringContainsString('/api/mobile/files/users/', app(ProtectedMedia::class)->documentUrl($student, 'passport', true));
    }

    public function test_migration_verifies_files_and_handles_legacy_paths_idempotently(): void
    {
        $student = $this->user('Student', null, ['passport' => 'legacy/passport.png']);
        Storage::disk('public')->put($student->passport, 'sensitive');
        Storage::disk('public')->put('online-kata-videos/orphan.mp4', 'old-video');
        Storage::disk('public')->put('avatar/keep.png', 'avatar');
        // Keep the deployment symlink outside this command test's public directory.
        $this->app->usePublicPath(storage_path('framework/testing/security-public'));
        $this->artisan('media:privatize')->assertSuccessful();
        Storage::disk('public')->assertExists($student->passport);
        $this->artisan('media:privatize', ['--apply' => true])->assertSuccessful();
        Storage::disk('public')->assertMissing($student->passport);
        Storage::disk('protected')->assertExists($student->passport);
        $this->assertSame('sensitive', Storage::disk('protected')->get($student->passport));
        Storage::disk('protected')->assertExists('online-kata-videos/orphan.mp4');
        Storage::disk('public')->assertExists('avatar/keep.png');
        $this->artisan('media:privatize', ['--apply' => true])->assertSuccessful();
    }

    public function test_conflicting_private_copy_never_deletes_public_original(): void
    {
        Storage::disk('public')->put('passport/a.png', 'original');
        Storage::disk('protected')->put('passport/a.png', 'different');
        try {
            app(ProtectedMedia::class)->migrateFile('passport/a.png');
            $this->fail('Conflicting copies must fail.');
        } catch (\RuntimeException $exception) {
            Storage::disk('public')->assertExists('passport/a.png');
            $this->assertStringContainsString('checksum', $exception->getMessage());
        }
    }

    public function test_online_video_is_private_and_authorized_in_both_rounds(): void
    {
        $organization = $this->user('Organization');
        $coach = $this->user('Coach', $organization);
        $otherCoach = $this->user('Coach', $organization);
        $student = $this->user('Student', $organization, ['coach_id' => $coach->id]);
        $championship = $this->form($organization)->championship;
        $tournament = Tournament::query()->create([
            'name' => 'Online', 'organization_id' => $organization->id, 'championship_id' => $championship->id,
            'tournament_type' => Tournament::KATA, 'tournament_type_kata' => Tournament::POINT_SYSTEM,
            'is_online_kata' => true, 'age_from' => 0, 'age_to' => 100, 'tatami' => 1, 'price' => 0,
            'date_commission' => now(), 'date' => now(), 'date_finish' => now()->addDay(), 'address' => 'City',
        ]);
        DB::table('tournament_treners')->insert([
            ['tournament_id' => $tournament->id, 'trener_id' => $coach->id],
            ['tournament_id' => $tournament->id, 'trener_id' => $otherCoach->id],
        ]);
        $application = StudentTournament::query()->create(['student_id' => $student->id, 'tournament_id' => $tournament->id,
            'online_kata_first_round_video_path' => 'online-kata-videos/first.mp4',
            'online_kata_second_round_video_path' => 'online-kata-videos/second.mp4']);
        foreach (['first', 'second'] as $round) {
            Storage::disk('protected')->put("online-kata-videos/$round.mp4", 'video-content');
            Storage::disk('public')->put("online-kata-videos/$round.mp4", 'legacy-copy');
            $url = "/api/panel/tournaments/$championship->id/items/$tournament->id/students/$application->id/online-kata-video/$round";
            $this->actingAs($coach)->get($url)->assertOk();
            $this->actingAs($otherCoach)->get($url)->assertForbidden();
            $this->actingAs($organization)->get($url)->assertOk();
            $this->get("/storage/online-kata-videos/$round.mp4")->assertNotFound();
        }
    }
}
