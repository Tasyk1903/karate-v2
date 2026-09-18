<?php

namespace Tests\Feature;

use App\Jobs\ImportExternalForm;
use App\Models\Championship;
use App\Models\ExternalForm;
use App\Models\ListTournament;
use App\Models\StudentTournament;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\TournamentStudentList;
use App\Models\User;
use App\Services\Tournaments\ExternalFormImportService;
use App\Services\Tournaments\ExternalFormRows;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Maatwebsite\Excel\Facades\Excel;
use Tests\TestCase;

class ExternalFormWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $organization;

    private ExternalForm $form;

    private Tournament $tournament;

    private ListTournament $personal;

    private ListTournament $group;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        $this->travelTo(now()->setDate(2026, 9, 5));
        foreach (['Organization', 'Secretary', 'Coach', 'Student'] as $role) {
            $id = DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $role]);
        }
        $this->organization = $this->user('Organization');
        $championship = Championship::create(['name' => 'Championship', 'banner' => 'banner.jpg', 'organization_id' => $this->organization->id]);
        $this->form = ExternalForm::create(['championship_id' => $championship->id, 'organization_name' => 'External team', 'token' => (string) Str::uuid(), 'status' => 'closed', 'data' => ['participants' => []]]);
        $this->tournament = Tournament::create(['name' => 'Kata', 'organization_id' => $this->organization->id, 'championship_id' => $championship->id,
            'tournament_type' => Tournament::KATA, 'tournament_type_kata' => Tournament::POINT_SYSTEM,
            'age_from' => 0, 'age_to' => 100, 'tatami' => 1, 'price' => 0, 'date_commission' => now(), 'date' => now()->addDay(), 'date_finish' => now()->addDays(2), 'address' => 'City']);
        $this->personal = $this->list('personal', 'all');
        $this->group = $this->list('group', null);
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function user(string $role, ?User $organization = null): User
    {
        return User::create(['first_name' => 'First', 'last_name' => 'Last', 'email' => Str::uuid().'@example.test', 'password' => 'password', 'role_id' => DB::table('roles')->where('name', $role)->value('id'), 'organization_id' => $organization?->id]);
    }

    private function list(string $type, ?string $gender): ListTournament
    {
        $template = TemplateStudentList::create(['name' => $type.' 10-11', 'list_type' => 'kata', 'kata_type' => $type, 'gender' => $gender, 'age_from' => 10, 'age_to' => 11,
            'rang_from' => 10, 'rang_to' => 0, 'user_id' => $this->organization->id]);

        return ListTournament::create(['tournament_id' => $this->tournament->id, 'template_student_list_id' => $template->id]);
    }

    private function row(string $first = 'Alex', array $extra = []): array
    {
        return array_replace(['first_name' => $first, 'last_name' => 'Student', 'birthday' => '01.01.2016', 'gender' => 'm', 'weight' => 30, 'rank' => '5 кю', 'club' => 'DOJO', 'coach_first_name' => 'John', 'coach_last_name' => 'Coach', 'category' => ['kata_point', 'kata_group'], 'kata_group' => 1], $extra);
    }

    private function save(array $rows): array
    {
        $rows = app(ExternalFormRows::class)->saveRows($this->form->fresh(), $rows, $this->organization);
        $this->form->refresh();

        return $rows;
    }

    private function import(bool $sync = false): array
    {
        return app(ExternalFormImportService::class)->import($this->form->fresh(), $this->organization, $sync);
    }

    private function base(): string
    {
        return '/api/panel/tournaments/'.$this->form->championship_id.'/forms/'.$this->form->id;
    }

    public function test_full_closed_editor_pagination_permissions_and_revision(): void
    {
        $rows = $this->save(array_map(fn ($n) => $this->row('Student '.$n), range(1, 45)));
        $secretary = $this->user('Secretary', $this->organization);
        $page = $this->actingAs($secretary)->getJson($this->base().'?page=3')->assertOk()->assertJsonCount(5, 'rows')->assertJsonPath('meta.total', 45);
        $revision = $page->json('revision');
        $changed = array_replace($rows[44], ['first_name' => 'Edited 45', 'best_results' => 'Winner', 'city' => 'Warsaw']);
        $this->putJson($this->base().'/rows', ['revision' => $revision, 'upserts' => [$changed]])->assertOk();
        $this->assertSame('Edited 45', $this->form->fresh()->data['participants'][44]['first_name']);
        $this->assertSame('closed', $this->form->fresh()->status);
        $this->putJson($this->base().'/rows', ['revision' => $revision, 'deletes' => [$rows[0]['row_id']]])->assertConflict();
        $this->putJson('/api/external-form/'.$this->form->token, ['participants' => []])->assertForbidden();
        $this->getJson($this->base().'?search=Edited')->assertJsonCount(1, 'rows');
        $revision = app(ExternalFormRows::class)->revision($this->form->fresh());
        $this->putJson($this->base().'/rows', ['revision' => $revision, 'deletes' => [$rows[0]['row_id']], 'upserts' => [$this->row('New')]])->assertOk()->assertJsonPath('meta.total', 45);
        foreach ([$this->user('Coach', $this->organization), $this->user('Organization')] as $foreign) {
            $this->actingAs($foreign)->getJson($this->base())->assertForbidden();
            $this->putJson($this->base().'/rows', ['revision' => $revision])->assertForbidden();
        }
        $this->assertDatabaseHas('activity_log', ['event' => 'external_form.rows.saved', 'causer_id' => $secretary->id]);
    }

    public function test_personal_and_group_kata_are_distinct_idempotent_applications_and_null_gender_matches(): void
    {
        $this->save([$this->row(), $this->row('Anna', ['gender' => 'f', 'category' => ['kata_group']])]);
        $result = $this->import();
        $this->assertSame(2, $result['created_users']);
        $this->assertSame(3, $result['attached']);
        $this->assertSame(0, $result['issues']);
        $this->assertDatabaseCount('student_tournaments', 2);
        $this->assertDatabaseCount('tournament_student_lists', 3);
        $this->assertSame(2, TournamentStudentList::where('list_tournament_id', $this->group->id)->count());
        $student = User::where('first_name', 'Alex')->firstOrFail();
        $this->assertDatabaseHas('student_tournaments', ['student_id' => $student->id, 'list_tournament_id' => $this->personal->id]);
        $this->assertSame('DOJO', $student->coach->club);
        $this->assertNull($student->club);
        $this->assertTrue($student->is_external);
        $this->assertTrue($student->coach->is_external);
        $this->assertSame(1, DB::table('external_form_coaches')->count());
        $repeat = $this->import();
        $this->assertSame(0, $repeat['created_users']);
        $this->assertSame(0, $repeat['attached']);
        $this->assertSame(0, $repeat['issues']);
        $this->assertSame(3, $repeat['unchanged']);
    }

    public function test_import_assigns_personal_and_group_entries_by_commission_birthday(): void
    {
        $this->tournament->update(['date_commission' => '2026-09-06 12:00:00', 'date' => '2026-09-07']);
        $this->save([$this->row('Birthday', ['birthday' => '06.09.2016']), $this->row('Older')]);
        $result = $this->import();
        $this->assertSame(0, $result['issues']);
        $this->assertSame(4, $result['attached']);
        $this->assertSame(2, TournamentStudentList::where('list_tournament_id', $this->personal->id)->count());
        $this->assertSame(2, TournamentStudentList::where('list_tournament_id', $this->group->id)->count());
        $this->assertSame(4, $this->import()['unchanged']);
    }

    public function test_group_identity_survives_add_remove_and_reimport(): void
    {
        $rows = $this->save([$this->row()]);
        $this->import();
        $uuid = DB::table('external_form_groups')->value('group_id');
        $rows[] = $this->row('Second', ['category' => ['kata_group']]);
        $rows = $this->save($rows);
        $this->assertSame(1, $this->import()['attached']);
        $this->assertSame([$uuid], TournamentStudentList::whereNotNull('group_id')->distinct()->pluck('group_id')->all());
        $rows[0]['category'] = ['kata_point'];
        $rows[0]['kata_group'] = null;
        $this->save($rows);
        $this->assertSame(1, $this->import()['removed']);
        $this->assertSame(1, TournamentStudentList::whereNotNull('group_id')->count());
        $this->save([$rows[0]]);
        $this->assertSame(1, $this->import()['removed']);
        $this->assertDatabaseCount('student_tournaments', 1);
        $this->assertDatabaseCount('tournament_student_lists', 1);
        $this->assertSame($uuid, DB::table('external_form_groups')->value('group_id'));
    }

    public function test_duplicate_rows_link_only_within_the_same_form(): void
    {
        $this->save([$this->row('Alex', ['category' => ['kata_point'], 'kata_group' => null]), $this->row('Alex', ['category' => ['kata_group']])]);
        $this->assertSame(1, $this->import()['created_users']);
        $this->assertSame(1, DB::table('external_form_students')->distinct()->count('user_id'));
        $this->assertDatabaseCount('tournament_student_lists', 2);
        $other = $this->form->replicate();
        $other->token = (string) Str::uuid();
        $other->save();
        $result = app(ExternalFormImportService::class)->import($other, $this->organization);
        $this->assertSame(1, $result['created_users']);
        $this->assertSame(2, DB::table('external_form_students')->distinct()->count('user_id'));
    }

    public function test_personal_changes_require_explicit_sync_and_have_separate_audit_events(): void
    {
        $rows = $this->save([$this->row()]);
        $this->import();
        $student = User::where('first_name', 'Alex')->firstOrFail();
        $rows[0]['weight'] = 35;
        $rows[0]['club'] = 'NEW CLUB';
        $this->save($rows);
        $result = $this->import();
        $this->assertContains('profile_pending', array_column($result['entries'], 'status'));
        $this->assertEquals(30, $student->fresh()->weight);
        $this->assertSame('DOJO', $student->fresh()->coach->club);
        $this->import(true);
        $this->assertEquals(35, $student->fresh()->weight);
        $this->assertSame('NEW CLUB', $student->fresh()->coach->club);
        foreach (['student.created', 'student.linked', 'student.updated', 'application.attached'] as $event) {
            $this->assertDatabaseHas('activity_log', ['event' => 'external_form.'.$event]);
        }
        $student->forceFill(['is_external' => false])->save();
        $rows[0]['weight'] = 40;
        $this->save($rows);
        $this->assertContains('profile_protected', array_column($this->import(true)['entries'], 'status'));
        $this->assertEquals(35, $student->fresh()->weight);
    }

    public function test_invalid_rows_do_not_prune_previous_applications_and_report_missing_tournament(): void
    {
        $rows = $this->save([$this->row()]);
        $this->import();
        $rows[0]['birthday'] = '31.02.2016';
        $this->form->update(['data' => ['participants' => $rows]]);
        $this->assertContains('invalid_row', array_column($this->import()['entries'], 'status'));
        $this->assertDatabaseCount('tournament_student_lists', 2);
        $this->assertSame('31.02.2016', $this->form->fresh()->data['participants'][0]['birthday']);
        $rows[0]['birthday'] = '01.01.2016';
        $rows[0]['category'] = ['kata_flag'];
        $rows[0]['kata_group'] = null;
        $this->save($rows);
        $this->assertContains('no_tournament', array_column($this->import()['entries'], 'status'));
        $this->assertDatabaseCount('tournament_student_lists', 2);
        $rows[0]['category'] = ['unknown'];
        $this->form->update(['data' => ['participants' => $rows]]);
        $this->assertContains('invalid_row', array_column($this->import()['entries'], 'status'));
        $this->assertDatabaseCount('tournament_student_lists', 2);
    }

    public function test_entire_group_uses_one_fallback_when_ages_do_not_match(): void
    {
        $this->save([$this->row(), $this->row('Older', ['birthday' => '01.01.2013', 'category' => ['kata_group']])]);
        $this->import();
        $members = TournamentStudentList::whereNotNull('group_id')->get();
        $this->assertCount(2, $members);
        $this->assertCount(1, $members->pluck('list_tournament_id')->unique());
        $this->assertNotEquals($this->group->id, $members->first()->list_tournament_id);
    }

    public function test_finished_lists_are_not_mutated_and_repeats_are_read_only(): void
    {
        $rows = $this->save([$this->row()]);
        $this->import();
        $this->tournament->update(['date_finish' => now()->subDay()]);
        $this->assertSame(2, $this->import()['unchanged']);
        $this->save([]);
        $this->assertContains('locked', array_column($this->import()['entries'], 'status'));
        $this->assertDatabaseCount('tournament_student_lists', 2);
    }

    public function test_async_run_is_deduplicated_and_report_is_authorized(): void
    {
        Bus::fake([ImportExternalForm::class]);
        $this->save([$this->row()]);
        $revision = app(ExternalFormRows::class)->revision($this->form->fresh());
        $run = $this->actingAs($this->organization)->postJson($this->base().'/import', ['revision' => $revision])->assertStatus(202)->json('run_id');
        $this->postJson($this->base().'/import', ['revision' => $revision])->assertJsonPath('run_id', $run);
        Bus::assertDispatchedTimes(ImportExternalForm::class, 1);
        (new ImportExternalForm($run))->handle(app(ExternalFormImportService::class));
        $this->getJson($this->base().'/imports/'.$run)->assertOk()->assertJsonPath('status', 'completed')->assertJsonPath('result.attached', 2);
        $this->assertDatabaseHas('activity_log', ['event' => 'external_form.import.completed']);
        $this->actingAs($this->user('Organization'))->getJson($this->base().'/imports/'.$run)->assertForbidden();
    }

    public function test_queued_import_rejects_changed_form_without_mutating_users(): void
    {
        Bus::fake([ImportExternalForm::class]);
        $rows = $this->save([$this->row()]);
        $run = $this->actingAs($this->organization)->postJson($this->base().'/import')->json('run_id');
        $rows[0]['weight'] = 40;
        $this->save($rows);
        (new ImportExternalForm($run))->handle(app(ExternalFormImportService::class));
        $this->getJson($this->base().'/imports/'.$run)->assertOk()->assertJsonPath('status', 'failed')->assertJsonPath('error_code', 'stale');
        $this->assertDatabaseCount('external_form_students', 0);
        $this->assertDatabaseHas('activity_log', ['event' => 'external_form.import.failed']);
    }

    public function test_external_student_profile_is_owned_and_club_comes_from_coach(): void
    {
        $this->save([$this->row()]);
        $this->import();
        $student = User::where('first_name', 'Alex')->firstOrFail();
        $this->actingAs($this->organization)->getJson('/api/panel/team/students/'.$student->id)->assertOk();
        $this->getJson('/api/panel/team?section=trainers')->assertOk()->assertJsonFragment(['club' => 'DOJO', 'is_external' => true, 'email' => null]);
        $this->assertDatabaseHas('tournament_treners', ['tournament_id' => $this->tournament->id, 'trener_id' => $student->coach_id]);
        Excel::fake();
        $queued = $this->getJson('/api/panel/tournaments/'.$this->form->championship_id.'/export?trainer_ids_csv='.$student->coach_id)->assertStatus(202);
        $this->runPanelTask($queued->json('task.id'));
        Excel::assertDownloaded('championship-'.$this->form->championship_id.'-participants.xlsx', fn ($export) => $export->collection()->contains(fn ($row) => $row[0] === 'Alex' && $row[1] === 'Student'));
        $this->actingAs($this->user('Organization'))->getJson('/api/panel/team/students/'.$student->id)->assertForbidden();
        $student->forceFill(['password' => 'external-password'])->save();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['email' => $student->email, 'password' => 'external-password'])->assertUnprocessable();
    }

    public function test_legacy_student_link_requires_explicit_confirmation_and_tournament_ownership(): void
    {
        $rows = $this->save([$this->row()]);
        $legacy = $this->user('Student');
        $legacy->forceFill(['email' => 'karaterating'.$legacy->id.'@karaterating.ru', 'first_name' => 'Alex', 'last_name' => 'Student', 'birthday' => '2016-01-01', 'weight' => 30, 'rang' => '5 кю'])->save();
        StudentTournament::create(['student_id' => $legacy->id, 'tournament_id' => $this->tournament->id, 'list_tournament_id' => $this->personal->id]);
        TournamentStudentList::create(['student_id' => $legacy->id, 'list_tournament_id' => $this->personal->id]);
        $this->actingAs($this->organization)->getJson($this->base().'/link-options')->assertOk()->assertJsonPath('rows.0.id', $legacy->id);
        $revision = app(ExternalFormRows::class)->revision($this->form->fresh());
        $payload = ['revision' => $revision, 'row_id' => $rows[0]['row_id'], 'student_id' => $legacy->id];
        $this->actingAs($this->user('Organization'))->postJson($this->base().'/links', $payload)->assertForbidden();
        $unrelated = $this->user('Student', $this->organization);
        $this->actingAs($this->organization)->postJson($this->base().'/links', array_replace($payload, ['student_id' => $unrelated->id]))->assertForbidden();
        $this->postJson($this->base().'/links', $payload)->assertOk();
        $this->assertSame('DOJO', $legacy->fresh()->coach->club);
        $this->assertEquals($this->organization->id, $legacy->fresh()->organization_id);
        $this->assertSame(0, $this->import()['created_users']);
        $this->assertDatabaseCount('tournament_student_lists', 2);
        $this->assertDatabaseHas('activity_log', ['event' => 'external_form.student.link.confirmed', 'subject_id' => $legacy->id]);
    }

    public function test_old_fragmented_group_ids_are_reconciled_without_duplicate_members(): void
    {
        $this->save([$this->row('Alex', ['category' => ['kata_group']]), $this->row('Second', ['category' => ['kata_group']])]);
        $this->import();
        DB::table('external_form_applications')->delete();
        DB::table('external_form_groups')->delete();
        TournamentStudentList::query()->update(['source_external_form_id' => null]);
        $members = TournamentStudentList::orderBy('id')->get();
        $uuid = $members[0]->group_id;
        $members[1]->update(['group_id' => (string) Str::uuid()]);
        $this->import();
        $this->assertDatabaseCount('tournament_student_lists', 2);
        $this->assertSame([$uuid], TournamentStudentList::distinct()->pluck('group_id')->all());
        $this->assertDatabaseHas('activity_log', ['event' => 'external_form.application.reconciled']);
    }
}
