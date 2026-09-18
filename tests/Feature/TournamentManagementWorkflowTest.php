<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\ExternalForm;
use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\OrganizationTournament;
use App\Models\Pool;
use App\Models\StudentTournament;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\TournamentStudentList;
use App\Models\User;
use App\Services\Tournaments\TournamentAssetUpdate;
use App\Services\Tournaments\TournamentLifecycle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TournamentManagementWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $org;

    private User $secretary;

    private Championship $champ;

    private Tournament $tournament;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        $this->travelTo(now()->setDate(2026, 9, 8)->setTime(12, 0));
        foreach (['Organization', 'Secretary', 'Coach', 'Student', 'Judge'] as $role) {
            $id = DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $role]);
        }
        $this->org = $this->user('Organization');
        $this->secretary = $this->user('Secretary', ['organization_id' => $this->org->id]);
        $this->champ = Championship::create(['name' => 'Championship', 'organization_id' => $this->org->id, 'banner' => 'championships/old.jpg']);
        $this->tournament = Tournament::create($this->input() + ['organization_id' => $this->org->id, 'championship_id' => $this->champ->id]);
        Storage::fake('public');
        $this->actingAs($this->org);
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function user(string $role, array $extra = []): User
    {
        return User::create(array_replace(['first_name' => 'Alex', 'last_name' => 'Example', 'email' => Str::uuid().'@example.test', 'password' => 'password', 'role_id' => DB::table('roles')->where('name', $role)->value('id')], $extra));
    }

    public function test_foreign_roles_and_guest_cannot_use_any_championship_route_or_mutate_data(): void
    {
        $other = $this->user('Organization');
        $actors = [$other, $this->user('Secretary', ['organization_id' => $other->id]), $this->user('Coach', ['organization_id' => $other->id]), $this->user('Student'), $this->user('Judge', ['organization_id' => $other->id]), null];
        $list = $this->list();
        $form = $this->form();
        $student = $this->user('Student');
        $application = StudentTournament::create(['student_id' => $student->id, 'tournament_id' => $this->tournament->id, 'list_tournament_id' => $list->id]);
        $pool = Pool::create(['student_id' => $student->id, 'tournament_id' => $this->tournament->id, 'list_id' => $list->id, 'round' => 1, 'type' => 'final']);
        $kata = KataPool::forceCreate(['student_id' => $student->id, 'tournament_id' => $this->tournament->id, 'list_id' => $list->id, 'round' => 'FINAL']);
        $params = ['championship' => $this->champ->id, 'tournament' => $this->tournament->id, 'list' => $list->id,
            'listTournament' => $list->id, 'form' => $form->id, 'run' => 99999, 'membership' => 99999, 'studentTournament' => $application->id,
            'pool' => $pool->id, 'kataPool' => $kata->id, 'coach' => $this->secretary->id, 'type' => 'kumite_protocols', 'kind' => 'coaches', 'round' => 'first'];
        $routes = collect(app('router')->getRoutes())->filter(fn ($r) => str_starts_with($r->uri(), 'api/panel/tournaments/{championship}'));
        $tables = ['championships', 'tournaments', 'external_forms', 'list_tournaments', 'pools', 'kata_pools', 'student_tournaments', 'activity_log', 'panel_tasks'];
        foreach ($actors as $actor) {
            if ($actor) {
                $this->acceptMobileAgreements($actor);
                $this->actingAs($actor);
            } else {
                auth()->forgetGuards();
                $this->app['auth']->guard()->logout();
            }
            $before = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
            foreach ($routes as $route) {
                $uri = preg_replace_callback('/\\{(\\w+)\\}/', fn ($m) => $params[$m[1]], $route->uri());
                $response = $this->json($route->methods()[0], '/'.$uri);
                $this->assertContains($response->status(), $actor ? [403, 404] : [401, 403, 404], ($actor?->role_id ?? 'guest').' '.$uri.' '.$response->getContent());
            }
            $after = collect($tables)->mapWithKeys(fn ($table) => [$table => DB::table($table)->get()->toJson()])->all();
            $this->assertSame($before, $after);
        }
    }

    private function input(): array
    {
        return ['name' => 'Tournament', 'region_id' => DB::table('regions')->value('id') ?? DB::table('regions')->insertGetId(['name' => 'Region']),
            'scale_id' => DB::table('scales')->value('id') ?? DB::table('scales')->insertGetId(['name' => 'Scale']), 'age_from' => 0, 'age_to' => 100,
            'tournament_type' => 1, 'tournament_type_kata' => null, 'tatami' => 1, 'price' => 0, 'date_commission' => '2026-09-08 18:00:00',
            'date' => '2026-09-08', 'date_finish' => '2026-09-08', 'address' => 'City'];
    }

    private function base(): string
    {
        return '/api/panel/tournaments/'.$this->champ->id.'/items/'.$this->tournament->id;
    }

    private function applicationBase(): string
    {
        return '/api/panel/tournament-applications';
    }

    private function list(): ListTournament
    {
        $template = TemplateStudentList::create(['name' => 'Kumite', 'list_type' => 'kumite', 'age_from' => 0, 'age_to' => 100, 'user_id' => $this->org->id]);

        return ListTournament::create(['tournament_id' => $this->tournament->id, 'template_student_list_id' => $template->id]);
    }

    private function form(): ExternalForm
    {
        return ExternalForm::create(['championship_id' => $this->champ->id, 'organization_name' => 'Team', 'token' => (string) Str::uuid(), 'status' => 'closed', 'data' => []]);
    }

    public function test_championship_edit_is_owned_and_logged_with_optional_image(): void
    {
        $url = '/api/panel/tournaments/'.$this->champ->id;
        $this->actingAs($this->secretary)->putJson($url, ['name' => 'Updated'])->assertOk();
        $this->assertSame('Updated', $this->champ->fresh()->name);
        $this->assertSame('championships/old.jpg', $this->champ->fresh()->banner);
        $this->post($url, ['_method' => 'PUT', 'name' => 'With image', 'banner' => UploadedFile::fake()->image('new.png')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('public')->assertExists($this->champ->fresh()->banner);
        $this->assertDatabaseHas('activity_log', ['event' => 'championship.updated', 'causer_id' => $this->secretary->id]);
        $this->actingAs($this->user('Organization'))->putJson($url, ['name' => 'Foreign'])->assertForbidden();
        $this->actingAs($this->user('Coach', ['organization_id' => $this->org->id]))->putJson($url, ['name' => 'Coach'])->assertForbidden();
        $this->assertSame('With image', $this->champ->fresh()->name);
    }

    public function test_tournament_files_replace_remove_and_validate_without_losing_unchanged_files(): void
    {
        $this->tournament->update(['regulation_document' => 'regulation_document/old.pdf', 'application_document' => 'application_document/old.pdf']);
        Storage::disk('public')->put('regulation_document/old.pdf', 'old');
        $this->post($this->base(), $this->input() + ['_method' => 'PUT', 'regulation_document' => UploadedFile::fake()->create('new.pdf', 10, 'application/pdf'), 'logo_report' => UploadedFile::fake()->image('logo.png'), 'accepts_organization_applications' => '1'], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('tournament.accepts_organization_applications', true);
        $fresh = $this->tournament->fresh();
        Storage::disk('public')->assertExists($fresh->regulation_document);
        Storage::disk('public')->assertMissing('regulation_document/old.pdf');
        Storage::disk('public')->assertExists($fresh->logo_report);
        $this->assertSame('application_document/old.pdf', $fresh->application_document);
        $this->putJson($this->base(), $this->input() + ['remove_application_document' => true, 'remove_logo_report' => true])->assertOk();
        $this->assertNull($this->tournament->fresh()->application_document);
        $this->assertNull($this->tournament->fresh()->logo_report);
        $this->assertSame($fresh->regulation_document, $this->tournament->fresh()->regulation_document);
        $this->post($this->base(), $this->input() + ['_method' => 'PUT', 'logo_report' => UploadedFile::fake()->create('bad.html', 10, 'text/html')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertDatabaseHas('activity_log', ['event' => 'tournament.updated']);
    }

    public function test_failed_asset_transaction_compensates_new_files_and_keeps_previous_data(): void
    {
        Storage::disk('public')->put('championships/old.jpg', 'old');
        $request = Request::create('/test', 'POST', [], [], ['banner' => UploadedFile::fake()->image('new.png')]);
        try {
            app(TournamentAssetUpdate::class)->save($this->champ, ['name' => 'Broken'], $request, ['banner' => 'championships'], fn () => throw new \RuntimeException('rollback'));
            $this->fail('Expected rollback');
        } catch (\RuntimeException $e) {
            $this->assertSame('rollback', $e->getMessage());
        }
        $this->assertSame('Championship', $this->champ->fresh()->name);
        $this->assertSame(['championships/old.jpg'], Storage::disk('public')->allFiles());
    }

    public function test_deletion_archives_tournament_without_destroying_results_memberships_or_files(): void
    {
        $student = $this->user('Student');
        $list = $this->list();
        StudentTournament::create(['student_id' => $student->id, 'tournament_id' => $this->tournament->id, 'list_tournament_id' => $list->id]);
        TournamentStudentList::create(['student_id' => $student->id, 'list_tournament_id' => $list->id]);
        Pool::create(['tournament_id' => $this->tournament->id, 'list_id' => $list->id, 'student_id' => $student->id, 'round' => '1']);
        $this->tournament->update(['regulation_document' => 'regulation_document/saved.pdf']);
        Storage::disk('public')->put('regulation_document/saved.pdf', 'saved');
        $this->actingAs($this->user('Organization'))->deleteJson($this->base())->assertForbidden();
        $this->actingAs($this->secretary)->deleteJson($this->base())->assertOk();
        $this->assertSoftDeleted('tournaments', ['id' => $this->tournament->id]);
        $this->assertDatabaseCount('pools', 1);
        $this->assertDatabaseCount('student_tournaments', 1);
        $this->assertDatabaseCount('tournament_student_lists', 1);
        Storage::disk('public')->assertExists('regulation_document/saved.pdf');
        $this->getJson($this->base())->assertNotFound();
        $this->getJson('/api/panel/tournaments/'.$this->champ->id)->assertOk()->assertJsonCount(0, 'items.data');
        $this->assertDatabaseHas('activity_log', ['event' => 'tournament.deleted']);
    }

    public function test_bulk_detach_is_atomic_scoped_and_keeps_participants(): void
    {
        $one = $this->user('Coach', ['organization_id' => $this->org->id]);
        $two = $this->user('Coach');
        $this->tournament->treners()->attach([$one->id, $two->id]);
        $this->postJson($this->base().'/coaches/bulk-detach', ['ids' => [$one->id, 99999]])->assertUnprocessable();
        $this->assertDatabaseCount('tournament_treners', 2);
        $this->actingAs($this->secretary)->postJson($this->base().'/coaches/bulk-detach', ['ids' => [$one->id, $two->id]])->assertOk();
        $this->assertDatabaseCount('tournament_treners', 0);
        $first = $this->list();
        $second = $this->list();
        Pool::create(['tournament_id' => $this->tournament->id, 'list_id' => $second->id, 'round' => '1']);
        $this->postJson($this->base().'/lists/bulk-detach', ['ids' => [$first->id, $second->id]])->assertUnprocessable();
        $this->assertDatabaseCount('list_tournaments', 2);
        $this->postJson($this->base().'/lists/bulk-detach', ['ids' => [$first->id]])->assertOk();
        $this->assertDatabaseCount('list_tournaments', 1);
        $this->assertDatabaseCount('pools', 1);
        $this->assertDatabaseHas('activity_log', ['event' => 'tournament.lists.bulk_detached']);
    }

    public function test_bulk_form_deletion_preserves_imported_students_and_blocks_running_imports(): void
    {
        $a = $this->form();
        $b = $this->form();
        $url = '/api/panel/tournaments/'.$this->champ->id.'/forms/bulk-delete';
        $run = DB::table('external_form_import_runs')->insertGetId(['external_form_id' => $b->id, 'actor_id' => $this->org->id, 'revision' => 'test', 'status' => 'running']);
        $this->postJson($url, ['ids' => [$a->id, $b->id]])->assertConflict();
        $this->assertDatabaseCount('external_forms', 2);
        DB::table('external_form_import_runs')->where('id', $run)->update(['status' => 'completed']);
        $student = $this->user('Student');
        $list = $this->list();
        StudentTournament::create(['student_id' => $student->id, 'tournament_id' => $this->tournament->id, 'list_tournament_id' => $list->id]);
        TournamentStudentList::create(['student_id' => $student->id, 'list_tournament_id' => $list->id, 'source_external_form_id' => $a->id]);
        $this->actingAs($this->secretary)->postJson($url, ['ids' => [$a->id, $b->id]])->assertOk();
        $this->assertDatabaseCount('external_forms', 0);
        $this->assertNotNull($student->fresh());
        $this->assertDatabaseCount('student_tournaments', 1);
        $this->assertDatabaseCount('tournament_student_lists', 1);
        $this->assertDatabaseHas('activity_log', ['event' => 'championship.forms.bulk_deleted']);
    }

    public function test_shared_asset_is_retained_until_last_reference_is_replaced(): void
    {
        Storage::disk('public')->put('championships/old.jpg', 'shared');
        $other = Championship::create(['name' => 'Another', 'organization_id' => $this->org->id, 'banner' => 'championships/old.jpg']);
        foreach ([$this->champ, $other] as $index => $champ) {
            $this->post('/api/panel/tournaments/'.$champ->id, ['_method' => 'PUT', 'name' => $champ->name, 'banner' => UploadedFile::fake()->image('new.png')], ['Accept' => 'application/json'])->assertOk();
            if ($index === 0) {
                Storage::disk('public')->assertExists('championships/old.jpg');
            }
        }
        Storage::disk('public')->assertMissing('championships/old.jpg');
    }

    public function test_generation_and_form_delete_obey_same_deadlines_as_ui(): void
    {
        $list = $this->list();
        $queued = $this->postJson($this->base().'/brackets/generate', [])->assertStatus(202);
        $this->assertSame('ready', $this->runPanelTask($queued->json('task.id'))->status);
        $this->travelTo(now()->setTime(18, 0, 1));
        $this->postJson($this->base().'/brackets/generate', [])->assertForbidden();
        $this->postJson($this->base().'/brackets/generate', ['list_id' => $list->id])->assertOk();
        $form = $this->form();
        $this->travelTo(now()->addDay()->startOfDay());
        $this->postJson($this->base().'/brackets/generate', ['list_id' => $list->id])->assertForbidden();
        $this->getJson('/api/panel/tournaments/'.$this->champ->id)->assertOk()->assertJsonPath('championship.can_delete_forms', false);
        $this->postJson('/api/panel/tournaments/'.$this->champ->id.'/forms/bulk-delete', ['ids' => [$form->id]])->assertForbidden();
        $this->assertDatabaseCount('external_forms', 1);
    }

    public function test_pending_and_accepted_applications_stop_attachment_at_commission_and_hide_deleted_championship(): void
    {
        $org = $this->user('Organization');
        $coach = $this->user('Coach', ['organization_id' => $org->id]);
        $this->tournament->update(['accepts_organization_applications' => true]);
        $application = OrganizationTournament::create(['tournament_id' => $this->tournament->id, 'applicant_organizer_id' => $org->id, 'is_success' => 'accepted']);
        $url = $this->applicationBase().'/'.$application->id;
        $this->actingAs($org);
        $this->travelTo(now()->setTime(18, 0, 1));
        $this->getJson($this->applicationBase().'?view=discover')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($url.'/team')->assertOk()->assertJsonPath('can_manage_team', false);
        $this->postJson($url.'/coaches', ['ids' => [$coach->id], 'attach' => true])->assertForbidden();
        $this->champ->delete();
        $this->getJson($this->applicationBase().'?view=outgoing')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($url.'/team')->assertForbidden();
        $this->assertDatabaseCount('tournament_treners', 0);
    }

    public function test_last_day_and_commission_boundaries_match_api_capabilities(): void
    {
        $this->getJson($this->base())->assertOk()->assertJsonPath('tournament.can_manage', true)->assertJsonPath('tournament.can_generate_all_brackets', true);
        $this->travelTo(now()->setTime(18, 0, 1));
        $this->assertFalse(TournamentLifecycle::commissionOpen($this->tournament));
        $this->getJson($this->base())->assertOk()->assertJsonPath('tournament.can_manage', true)->assertJsonPath('tournament.can_generate_all_brackets', false);
        $this->travelTo(now()->setTime(23, 59, 59));
        $this->putJson($this->base(), $this->input())->assertOk();
        $this->getJson($this->base())->assertOk()->assertJsonPath('tournament.status', 'active');
        $this->travelTo(now()->addSecond());
        $this->getJson($this->base())->assertOk()->assertJsonPath('tournament.can_manage', false)->assertJsonPath('tournament.status', 'completed')->assertJsonPath('tournament.can_delete', true);
        $this->putJson($this->base(), $this->input())->assertForbidden();
        $this->postJson($this->base().'/coaches/bulk-detach', ['ids' => [$this->org->id]])->assertForbidden();
    }

    public function test_application_catalog_is_opt_in_and_application_is_role_scoped_idempotent_and_notified(): void
    {
        $applicant = $this->user('Organization');
        $this->actingAs($applicant)->getJson($this->applicationBase().'?view=discover')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($this->applicationBase().'/'.$this->tournament->id.'/apply')->assertForbidden();
        $this->tournament->update(['accepts_organization_applications' => true]);
        $this->getJson($this->applicationBase().'?view=discover')->assertOk()->assertJsonCount(1, 'data')->assertJsonMissingPath('data.0.students');
        $id = $this->postJson($this->applicationBase().'/'.$this->tournament->id.'/apply')->assertOk()->assertJsonPath('status', 'pending')->json('id');
        $this->postJson($this->applicationBase().'/'.$this->tournament->id.'/apply')->assertOk()->assertJsonPath('id', $id);
        $this->assertDatabaseCount('organization_tournaments', 1);
        $this->assertDatabaseCount('user_alerts', 1);
        $this->assertDatabaseHas('user_alert_user', ['user_id' => $this->org->id, 'read_at' => null]);
        $this->getJson($this->applicationBase().'/'.$id.'/team')->assertForbidden();
        $this->actingAs($this->user('Secretary', ['organization_id' => $applicant->id]))->postJson($this->applicationBase().'/'.$this->tournament->id.'/apply')->assertForbidden();
        $this->actingAs($this->org)->getJson($this->applicationBase().'?view=incoming')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_accepted_application_only_grants_own_team_access_and_cancel_preserves_results(): void
    {
        $applicant = $this->user('Organization');
        $coach = $this->user('Coach', ['organization_id' => $applicant->id]);
        $manual = $this->user('Coach', ['organization_id' => $applicant->id]);
        $application = OrganizationTournament::create(['tournament_id' => $this->tournament->id, 'applicant_organizer_id' => $applicant->id, 'is_success' => 'pending']);
        $url = $this->applicationBase().'/'.$application->id;
        $this->actingAs($applicant)->postJson($url.'/decision', ['status' => 'accepted'])->assertForbidden();
        $this->actingAs($this->secretary)->postJson($url.'/decision', ['status' => 'accepted'])->assertOk();
        $this->assertDatabaseHas('user_alert_user', ['user_id' => $applicant->id, 'read_at' => null]);
        $this->tournament->treners()->attach($manual->id);
        $this->actingAs($applicant)->getJson($url.'/team')->assertOk()->assertJsonCount(2, 'coaches.data');
        $this->postJson($url.'/coaches', ['ids' => [$coach->id, $this->org->id], 'attach' => true])->assertUnprocessable();
        $this->postJson($url.'/coaches', ['ids' => [$coach->id], 'attach' => true])->assertOk();
        $this->assertDatabaseCount('tournament_treners', 2);
        $this->getJson($this->base())->assertForbidden();
        $this->putJson($this->base(), $this->input())->assertForbidden();
        $this->deleteJson($this->base())->assertForbidden();
        $student = $this->user('Student', ['coach_id' => $coach->id]);
        StudentTournament::create(['student_id' => $student->id, 'tournament_id' => $this->tournament->id]);
        $this->actingAs($this->org)->postJson($url.'/decision', ['status' => 'canceled'])->assertOk();
        $this->assertDatabaseHas('tournament_treners', ['trener_id' => $manual->id]);
        $this->assertDatabaseMissing('tournament_treners', ['trener_id' => $coach->id]);
        $this->assertDatabaseCount('student_tournaments', 1);
        $this->actingAs($applicant)->getJson($url.'/team')->assertForbidden();
        $application->update(['is_success' => '1']);
        $this->getJson($url.'/team')->assertForbidden();
        $this->assertDatabaseHas('activity_log', ['event' => 'tournament.application.decided']);
    }
}
