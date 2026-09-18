<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\ExternalForm;
use App\Models\ListTournament;
use App\Models\Pool;
use App\Models\StudentTournament;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\TournamentStudentList;
use App\Models\User;
use App\Services\Tournaments\ListCompatibility;
use App\Services\Tournaments\ListRankCriteria;
use App\Services\Tournaments\StudentTournamentListAssignmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class TournamentListWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $org;

    private User $coach;

    private Championship $champ;

    private Tournament $tournament;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        $this->travelTo(now()->setDate(2026, 9, 5));
        foreach (['Organization', 'Secretary', 'Coach', 'Student'] as $name) {
            $id = DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $name]);
        }
        $this->org = $this->user('Organization');
        $this->coach = $this->user('Coach', ['organization_id' => $this->org->id, 'club' => 'DOJO']);
        $this->champ = Championship::create(['name' => 'Championship', 'banner' => 'banner.jpg', 'organization_id' => $this->org->id]);
        $this->tournament = Tournament::create(['name' => 'Tournament', 'organization_id' => $this->org->id, 'championship_id' => $this->champ->id,
            'tournament_type' => Tournament::KATA, 'tournament_type_kata' => Tournament::POINT_SYSTEM, 'age_from' => 0, 'age_to' => 100,
            'tatami' => 1, 'price' => 0, 'date_commission' => now(), 'date' => now()->addDay(), 'date_finish' => now()->addDays(2), 'address' => 'City']);
        $this->actingAs($this->org);
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function user(string $role, array $extra = []): User
    {
        return User::create(array_replace(['first_name' => 'Alex', 'last_name' => 'Student', 'email' => Str::uuid().'@example.test', 'password' => 'password', 'role_id' => DB::table('roles')->where('name', $role)->value('id')], $extra));
    }

    private function student(): User
    {
        return $this->user('Student', ['coach_id' => $this->coach->id, 'organization_id' => $this->org->id, 'birthday' => '2016-01-01', 'weight' => 30, 'rang' => '5 кю', 'gender' => 'm']);
    }

    private function template(string $type = 'personal', array $extra = []): TemplateStudentList
    {
        return TemplateStudentList::create(array_replace(['name' => $type, 'list_type' => $type === 'kumite' ? 'kumite' : 'kata', 'kata_type' => $type === 'kumite' ? null : $type, 'age_from' => 10, 'age_to' => 11, 'gender' => 'all', 'weight_from' => 20, 'weight_to' => 40, 'rang_from' => 10, 'rang_to' => 0, 'user_id' => $this->org->id], $extra));
    }

    private function list(string $type = 'personal', array $extra = []): ListTournament
    {
        return ListTournament::create(['tournament_id' => $this->tournament->id, 'template_student_list_id' => $this->template($type, $extra)->id]);
    }

    private function membership(User $student, ListTournament $list, ?string $group = null): TournamentStudentList
    {
        StudentTournament::firstOrCreate(['student_id' => $student->id, 'tournament_id' => $this->tournament->id], ['list_tournament_id' => $list->id]);

        return TournamentStudentList::create(['student_id' => $student->id, 'list_tournament_id' => $list->id, 'group_id' => $group]);
    }

    private function base(): string
    {
        return '/api/panel/tournaments/'.$this->champ->id.'/items/'.$this->tournament->id;
    }

    private function entry(User $student): int
    {
        return StudentTournament::where('student_id', $student->id)->where('tournament_id', $this->tournament->id)->value('id');
    }

    public function test_group_validation_assignment_and_display_use_commission_date(): void
    {
        $this->tournament->update(['date_commission' => '2026-09-06 12:00:00']);
        $list = $this->list('group');
        $older = $this->student();
        $birthday = $this->student();
        $birthday->update(['birthday' => '2016-09-06']);
        $this->getJson($this->base().'/student-attach-options')->assertOk()->assertJsonFragment(['id' => $birthday->id, 'age' => 10]);
        $this->postJson($this->base().'/students/group', ['student_ids' => [$older->id, $birthday->id]])->assertOk();
        $members = TournamentStudentList::where('list_tournament_id', $list->id)->get();
        $this->assertCount(2, $members);
        $this->assertSame(1, $members->pluck('group_id')->unique()->count());
        $this->getJson($this->base())->assertOk()->assertJsonFragment(['id' => $birthday->id, 'age' => 10]);

        $younger = $this->student();
        $younger->update(['birthday' => '2016-09-07']);
        $another = $this->student();
        $this->postJson($this->base().'/students/group', ['student_ids' => [$another->id, $younger->id]])->assertUnprocessable();
        $this->assertDatabaseCount('tournament_student_lists', 2);
    }

    public function test_unknown_birthday_does_not_match_using_stale_age_or_zero(): void
    {
        $student = $this->student();
        $student->update(['birthday' => null, 'age' => 10]);
        $list = $this->list('personal', ['age_from' => 0, 'age_to' => 100]);
        $group = $this->list('group', ['age_from' => 0, 'age_to' => 100]);
        $service = app(StudentTournamentListAssignmentService::class);
        $this->assertNotSame($list->id, $service->bestListFor($student, $this->tournament->fresh())->id);
        $this->assertNotSame($group->id, $service->bestGroupList($this->tournament->fresh(), collect([$student]))->id);
        $this->assertFalse($service->groupAgesMatch(collect([$student]), $this->tournament));
    }

    public function test_legacy_rank_directions_white_belt_dan_and_invalid_ranks(): void
    {
        $criteria = new ListRankCriteria;
        foreach ([['8 кю', 8, 4, true], ['4 kyu', 8, 4, true], ['9 кю', 8, 4, false], ['3 кю', 8, 4, false], ['8 кю', 4, 8, true], ['10 кю', 4, 8, true], ['7 кю', 4, 8, false], ['0 кю', 10, 9, true], ['1 дан', 10, 1, false], ['3 dan', 8, 0, true], ['bad', 8, 0, false], ['5 кю', 5, 5, true], ['4 кю', 5, 5, false]] as [$rank, $from, $to, $expected]) {
            $this->assertSame($expected, $criteria->matches($rank, $from, $to), $rank.' '.$from.' '.$to);
        }
        $this->assertTrue($criteria->matches(null, null, null));
        $this->assertSame(0, $criteria->number('1 дан'));
        $this->assertSame(10, $criteria->number('0 кю'));
    }

    public function test_both_template_entry_points_accept_descending_kyu_without_reversing_values(): void
    {
        $this->tournament->update(['tournament_type' => Tournament::KUMITE, 'tournament_type_kata' => null]);
        $data = $this->template('kumite')->only(['name', 'list_type', 'kata_type', 'age_from', 'age_to', 'gender', 'weight_from', 'weight_to', 'rang_from', 'rang_to']);
        $data['rang_from'] = 8;
        $data['rang_to'] = 4;
        $this->postJson('/api/panel/template-student-lists', $data)->assertCreated()->assertJsonPath('item.rang_from', 8)->assertJsonPath('item.rang_to', 4);
        $this->postJson($this->base().'/lists/create', $data)->assertOk();
        $data['rang_from'] = 4;
        $data['rang_to'] = 8;
        $this->postJson('/api/panel/template-student-lists', $data)->assertCreated()->assertJsonPath('item.rang_to', 8);
    }

    public function test_incompatible_lists_rejected_atomically_and_excluded_from_autopick(): void
    {
        $personal = $this->template('personal');
        $wrong = $this->template('kumite');
        $this->postJson($this->base().'/lists', ['template_ids' => [$personal->id, $wrong->id]])->assertUnprocessable();
        $this->assertDatabaseCount('list_tournaments', 0);
        $this->postJson($this->base().'/lists', ['template_ids' => [$personal->id]])->assertOk();
        $student = $this->student();
        $this->assertSame($personal->id, app(StudentTournamentListAssignmentService::class)->bestListFor($student, $this->tournament->fresh())->template_student_list_id);
        $this->tournament->update(['tournament_type' => Tournament::KUMITE, 'tournament_type_kata' => null]);
        $kumite = $this->list('kumite');
        $this->assertSame($kumite->id, app(StudentTournamentListAssignmentService::class)->bestListFor($student, $this->tournament->fresh())->id);
        $this->tournament->update(['tournament_type' => Tournament::KATA, 'tournament_type_kata' => Tournament::FLAG_SYSTEM]);
        $flag = $this->list('flag');
        $this->assertSame($flag->id, app(StudentTournamentListAssignmentService::class)->bestListFor($student, $this->tournament->fresh())->id);
        $this->assertFalse(app(ListCompatibility::class)->matches($personal, $this->tournament, true));
    }

    public function test_kata_weight_update_preserves_every_membership_and_group_uuid(): void
    {
        $student = $this->student();
        $p = $this->membership($student, $this->list());
        $g = $this->membership($student, $this->list('group'), (string) Str::uuid());
        $this->patchJson($this->base().'/students/'.$this->entry($student), ['weight' => 35])->assertOk();
        $this->assertEquals(35, $student->fresh()->weight);
        $this->assertEquals($g->group_id, $g->fresh()->group_id);
        $this->assertEquals($p->list_tournament_id, $p->fresh()->list_tournament_id);
        $this->getJson($this->base().'?list_id='.$g->list_tournament_id)->assertOk()->assertJsonCount(1, 'detail.students.data')->assertJsonCount(2, 'detail.students.data.0.memberships');
    }

    public function test_kumite_weight_moves_only_selected_application_and_generated_lists_roll_back(): void
    {
        $this->tournament->update(['tournament_type' => Tournament::KUMITE, 'tournament_type_kata' => null]);
        $student = $this->student();
        $light = $this->list('kumite', ['weight_to' => 30]);
        $heavy = $this->list('kumite', ['weight_from' => 31, 'weight_to' => 45]);
        $membership = $this->membership($student, $light);
        $other = $this->membership($student, $this->list('kumite', ['name' => 'Other category']));
        $id = $this->entry($student);
        $this->patchJson($this->base().'/students/'.$id, ['weight' => 35, 'membership_id' => $membership->id])->assertOk();
        $this->assertEquals($heavy->id, $membership->fresh()->list_tournament_id);
        $this->assertEquals($other->list_tournament_id, $other->fresh()->list_tournament_id);
        Pool::create(['tournament_id' => $this->tournament->id, 'list_id' => $heavy->id, 'round' => '1', 'student_id' => $student->id]);
        $this->patchJson($this->base().'/students/'.$id, ['weight' => 29, 'membership_id' => $membership->id])->assertUnprocessable();
        $this->assertEquals(35, $student->fresh()->weight);
        $this->assertDatabaseCount('pools', 1);
        $this->deleteJson($this->base().'/students/'.$id, ['membership_id' => $membership->id])->assertUnprocessable();
    }

    public function test_group_move_and_detach_preserve_personal_entry_and_reject_ambiguous_request(): void
    {
        $student = $this->student();
        $second = $this->student();
        $personal = $this->membership($student, $this->list());
        $group = $this->list('group');
        $target = $this->list('group', ['name' => 'Another group list']);
        $uuid = (string) Str::uuid();
        $one = $this->membership($student, $group, $uuid);
        $two = $this->membership($second, $group, $uuid);
        $id = $this->entry($student);
        $this->deleteJson($this->base().'/students/'.$id)->assertUnprocessable();
        $this->putJson($this->base().'/students/'.$id.'/list', ['membership_id' => $one->id, 'list_tournament_id' => $personal->list_tournament_id])->assertUnprocessable();
        $this->putJson($this->base().'/students/'.$id.'/list', ['membership_id' => $one->id, 'list_tournament_id' => $target->id])->assertOk();
        $this->assertEquals($target->id, $one->fresh()->list_tournament_id);
        $this->assertEquals($target->id, $two->fresh()->list_tournament_id);
        $this->assertSame($uuid, $one->fresh()->group_id);
        $this->deleteJson($this->base().'/students/'.$id, ['membership_id' => $one->id])->assertOk();
        $this->assertDatabaseCount('tournament_student_lists', 1);
        $this->assertDatabaseCount('student_tournaments', 1);
        $this->assertDatabaseHas('student_tournaments', ['student_id' => $student->id, 'list_tournament_id' => $personal->list_tournament_id]);
        $this->assertDatabaseHas('activity_log', ['event' => 'tournament.application.moved']);
        $this->assertDatabaseHas('activity_log', ['event' => 'tournament.application.detached']);
    }

    public function test_group_picker_searches_after_300_and_revalidates_all_ids(): void
    {
        $hash = bcrypt('password');
        $role = DB::table('roles')->where('name', 'Student')->value('id');
        foreach (range(1, 330) as $n) {
            DB::table('users')->insert(['first_name' => 'Student', 'last_name' => sprintf('Name%03d', $n), 'email' => $n.'@example.test', 'password' => $hash, 'role_id' => $role, 'coach_id' => $this->coach->id, 'organization_id' => $this->org->id, 'birthday' => '2016-01-01', 'gender' => 'm', 'rang' => '5 кю']);
        }
        $this->getJson($this->base().'/student-attach-options?page=11')->assertOk()->assertJsonCount(30, 'data')->assertJsonPath('meta.total', 330);
        $found = $this->getJson($this->base().'/student-attach-options?search=Name330')->assertOk()->assertJsonCount(1, 'data')->json('data.0.id');
        $another = User::where('email', '1@example.test')->value('id');
        $foreign = $this->user('Student');
        $this->postJson($this->base().'/students/group', ['student_ids' => [$found, $foreign->id]])->assertUnprocessable();
        $this->assertDatabaseCount('tournament_student_lists', 0);
        $this->list('group');
        $this->postJson($this->base().'/students/group', ['student_ids' => [$found, $another]])->assertOk();
        $this->getJson($this->base().'/student-attach-options?search=Name330')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($this->base().'/students/group', ['student_ids' => [$found, $another]])->assertUnprocessable();
        $this->assertDatabaseCount('tournament_student_lists', 2);
        $this->actingAs($this->user('Organization'))->getJson($this->base().'/student-attach-options')->assertForbidden();
    }

    public function test_generated_kata_table_protects_source_target_and_empty_list(): void
    {
        $student = $this->student();
        $source = $this->list('group');
        $target = $this->list('group');
        $membership = $this->membership($student, $source, (string) Str::uuid());
        DB::table('kata_pools')->insert(['tournament_id' => $this->tournament->id, 'list_id' => $target->id]);
        $this->putJson($this->base().'/students/'.$this->entry($student).'/list', ['membership_id' => $membership->id, 'list_tournament_id' => $target->id])->assertUnprocessable();
        $this->deleteJson($this->base().'/lists/'.$target->id)->assertUnprocessable();
        $this->assertEquals($source->id, $membership->fresh()->list_tournament_id);
        DB::table('kata_pools')->where('list_id', $target->id)->update(['list_id' => $source->id]);
        $this->deleteJson($this->base().'/students/'.$this->entry($student), ['membership_id' => $membership->id])->assertUnprocessable();
        $this->assertDatabaseCount('kata_pools', 1);
        $this->assertDatabaseCount('tournament_student_lists', 1);
    }

    public function test_membership_cannot_be_spoofed_and_secretary_has_scoped_access(): void
    {
        $student = $this->student();
        $another = $this->student();
        $list = $this->list();
        $one = $this->membership($student, $list);
        $two = $this->membership($another, $list);
        $this->deleteJson($this->base().'/students/'.$this->entry($student), ['membership_id' => $two->id])->assertUnprocessable();
        $this->assertDatabaseCount('tournament_student_lists', 2);
        $this->actingAs($this->user('Secretary', ['organization_id' => $this->org->id]));
        $this->getJson($this->base().'/student-attach-options')->assertOk();
        $this->deleteJson($this->base().'/students/'.$this->entry($student), ['membership_id' => $one->id])->assertOk();
        $this->assertDatabaseCount('tournament_student_lists', 1);
        $this->actingAs($this->coach)->getJson($this->base().'/student-attach-options')->assertForbidden();
    }

    public function test_fallback_never_shadows_a_compatible_list_added_later(): void
    {
        $student = $this->student();
        $service = app(StudentTournamentListAssignmentService::class);
        $fallback = $service->bestListFor($student, $this->tournament->fresh());
        $list = $this->list('personal', ['rang_from' => 0, 'rang_to' => 100]);
        $this->assertNotEquals($fallback->id, $list->id);
        $this->assertSame($list->id, $service->bestListFor($student, $this->tournament->fresh())->id);
        $student->update(['gender' => 'f']);
        $list->templateStudentList->update(['gender' => 'm']);
        $this->assertSame($fallback->id, $service->bestListFor($student, $this->tournament->fresh())->id);
    }

    public function test_shared_template_cannot_change_application_kind_or_cascade_delete_participants(): void
    {
        $list = $this->list();
        $template = $list->templateStudentList;
        $this->membership($this->student(), $list);
        $data = $template->only(['name', 'list_type', 'kata_type', 'age_from', 'age_to', 'gender', 'weight_from', 'weight_to', 'rang_from', 'rang_to']);
        $data['kata_type'] = 'group';
        $this->putJson('/api/panel/template-student-lists/'.$template->id, $data)->assertUnprocessable();
        $this->deleteJson('/api/panel/template-student-lists/'.$template->id)->assertUnprocessable();
        $this->assertDatabaseCount('tournament_student_lists', 1);
        $this->assertSame('personal', $template->fresh()->kata_type);
    }

    public function test_import_tracking_survives_manual_move_and_is_cleaned_on_detach(): void
    {
        $student = $this->student();
        $source = $this->list();
        $target = $this->list();
        $membership = $this->membership($student, $source);
        $form = ExternalForm::create(['championship_id' => $this->champ->id, 'organization_name' => 'External team', 'token' => (string) Str::uuid(), 'status' => 'closed', 'data' => []]);
        $membership->update(['source_external_form_id' => $form->id]);
        DB::table('external_form_applications')->insert(['external_form_id' => $form->id, 'row_id' => (string) Str::uuid(), 'category' => 'kata_point', 'tournament_id' => $this->tournament->id, 'user_id' => $student->id, 'membership_id' => $membership->id]);
        $this->putJson($this->base().'/students/'.$this->entry($student).'/list', ['membership_id' => $membership->id, 'list_tournament_id' => $target->id])->assertOk();
        $this->assertDatabaseHas('external_form_applications', ['membership_id' => $membership->id]);
        $this->assertEquals($form->id, $membership->fresh()->source_external_form_id);
        $this->deleteJson($this->base().'/students/'.$this->entry($student), ['membership_id' => $membership->id])->assertOk();
        $this->assertDatabaseCount('external_form_applications', 0);
        $this->assertDatabaseCount('student_tournaments', 0);
        $this->assertDatabaseCount('tournament_student_lists', 0);
    }
}
