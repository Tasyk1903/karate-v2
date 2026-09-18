<?php

namespace Tests\Feature;

use App\Jobs\RunPanelTask;
use App\Models\Championship;
use App\Models\ListTournament;
use App\Models\MobileAccessToken;
use App\Models\PanelTask;
use App\Models\StudentTournament;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\TournamentStudentList;
use App\Models\User;
use App\Services\Tournaments\CoachTournamentAccess;
use App\Services\Tournaments\OnlineKataPaymentService;
use App\Services\Tournaments\StudentTournamentListAssignmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileTournamentEnrollmentTest extends TestCase
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
        $this->travelTo(now()->setDate(2026, 9, 5)->setTime(12, 0, 0));
        foreach (['Organization', 'Secretary', 'Coach', 'Student'] as $name) {
            $id = DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $name]);
        }
        $this->org = $this->user('Organization', ['can_edit_coaches' => true]);
        $this->coach = $this->user('Coach', ['organization_id' => $this->org->id, 'club' => 'DOJO']);
        $this->champ = Championship::create(['name' => 'Championship', 'banner' => 'banner.jpg', 'organization_id' => $this->org->id]);
        $this->tournament = Tournament::create(['name' => 'Tournament', 'organization_id' => $this->org->id, 'championship_id' => $this->champ->id,
            'tournament_type' => Tournament::KATA, 'tournament_type_kata' => Tournament::POINT_SYSTEM, 'age_from' => 0, 'age_to' => 100,
            'tatami' => 1, 'price' => 0, 'date_commission' => now()->addDay(), 'date' => now()->addDays(2), 'date_finish' => now()->addDays(3), 'address' => 'City']);
        DB::table('tournament_treners')->insert(['tournament_id' => $this->tournament->id, 'trener_id' => $this->coach->id]);
        MobileAccessToken::create(['user_id' => $this->coach->id, 'name' => 'test', 'token' => hash('sha256', 'tournament-test'), 'expires_at' => now()->addMonth()]);
        $this->acceptMobileAgreements($this->coach);
        $this->withToken('tournament-test');
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
        return '/api/mobile/championships/'.$this->champ->id.'/tournaments/'.$this->tournament->id;
    }

    private function entry(User $student): int
    {
        return StudentTournament::where('student_id', $student->id)->where('tournament_id', $this->tournament->id)->value('id');
    }

    public function test_student_self_enrollment_does_not_remove_team_mates_and_obeys_access(): void
    {
        $student = $this->student();
        $other = $this->student();
        $group = $this->list('group');
        $mine = $this->membership($student, $group, 'team');
        $peer = $this->membership($other, $group, 'team');
        $this->list('personal');
        $this->acceptMobileAgreements($student);
        MobileAccessToken::create(['user_id' => $student->id, 'name' => 'test', 'token' => hash('sha256', 'student-tournament'), 'expires_at' => now()->addMonth()]);
        $this->withToken('student-tournament');
        $this->getJson('/api/mobile/championships?ownership=all')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->base())->assertOk()->assertJsonPath('tournament.can_attach_students', false);
        $this->postJson($this->base().'/self')->assertForbidden();
        $this->coach->update(['can_attach_to_tournaments_for_students' => true]);
        $this->getJson($this->base().'/lists')->assertOk();
        $this->postJson($this->base().'/self', ['student_id' => $other->id])->assertOk();
        $this->postJson($this->base().'/self')->assertOk();
        $this->assertDatabaseCount('tournament_student_lists', 3);
        $this->deleteJson($this->base().'/self/'.$peer->id)->assertNotFound();
        $this->deleteJson($this->base().'/self/'.$mine->id)->assertOk();
        $this->assertDatabaseHas('tournament_student_lists', ['id' => $peer->id, 'group_id' => 'team']);
        $this->assertDatabaseCount('tournament_student_lists', 2);
        $this->getJson('/api/mobile/students/'.$other->id)->assertForbidden();
        $this->getJson('/api/mobile/students/'.$other->id.'/public?tournament_id='.$this->tournament->id.'&championship_id='.$this->champ->id)
            ->assertOk()->assertJsonMissingPath('student.email')->assertJsonPath('student.capabilities.edit', false);
        $this->tournament->update(['date' => today()]);
        $personal = TournamentStudentList::where('student_id', $student->id)->firstOrFail();
        $this->deleteJson($this->base().'/self/'.$personal->id)->assertForbidden();
        $this->coach->delete();
        $this->getJson($this->base())->assertForbidden();
        $this->getJson('/api/mobile/championships?ownership=all')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_catalog_does_not_grant_direct_access_to_unassigned_details(): void
    {
        $this->getJson($this->base())->assertOk()->assertJsonPath('tournament.can_attach_students', true);
        DB::table('tournament_treners')->delete();
        $this->getJson('/api/mobile/championships/'.$this->champ->id.'?ownership=all&status=all')->assertOk()->assertJsonPath('data.0.can_open', false);
        foreach (['', '/students', '/coaches', '/lists', '/students/attach-options', '/documents/regulation_document'] as $path) {
            $this->getJson($this->base().$path)->assertForbidden();
        }
        $this->postJson($this->base().'/students', ['student_ids' => [$this->student()->id]])->assertForbidden();
    }

    public function test_student_counts_only_assigned_tournaments_and_owns_export_task(): void
    {
        Storage::fake('protected');
        Bus::fake();
        $student = $this->student();
        $this->membership($student, $this->list());
        $this->acceptMobileAgreements($student);
        MobileAccessToken::create(['user_id' => $student->id, 'name' => 'test', 'token' => hash('sha256', 'self-export'), 'expires_at' => now()->addMonth()]);
        $this->withToken('self-export');
        $hidden = $this->tournament->replicate();
        $hidden->save();
        $this->tournament->update(['date_finish' => today()->subDay()]);
        $this->getJson('/api/mobile/championships?status=completed&ownership=all')->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.tournaments_count', 1)->assertJsonPath('data.0.status', 'completed');
        $this->getJson('/api/mobile/championships?status=active')->assertJsonCount(0, 'data');
        $this->postJson($this->base().'/exports', ['format' => 'results'])->assertForbidden();
        $id = $this->postJson($this->base().'/exports', ['format' => 'lists-excel'])->assertAccepted()->json('task.id');
        $this->getJson('/api/mobile/tasks/'.$id)->assertOk()->assertJsonPath('task.status', 'queued');
        app()->call([new RunPanelTask($id), 'handle']);
        $task = PanelTask::findOrFail($id);
        $this->assertSame('ready', $task->status);
        Storage::disk('protected')->assertExists($task->path);
        $this->getJson('/api/mobile/tasks/'.$id)->assertJsonPath('task.download_url', url('/api/mobile/tasks/'.$id.'/file'));
        $this->get('/api/mobile/tasks/'.$id.'/file')->assertOk();
        $this->withToken('tournament-test')->getJson('/api/mobile/tasks/'.$id)->assertForbidden();
        DB::table('tournament_treners')->delete();
        $this->withToken('self-export')->get('/api/mobile/tasks/'.$id.'/file')->assertForbidden();
    }

    public function test_organization_permission_cannot_be_bypassed_by_student_self_registration_flag(): void
    {
        $student = $this->student();
        $this->coach->update(['can_attach_to_tournaments_for_students' => true]);
        $this->org->update(['can_edit_coaches' => false]);
        $this->getJson($this->base())->assertJsonPath('tournament.can_attach_students', false);
        $this->getJson($this->base().'/students/attach-options')->assertForbidden();
        $this->postJson($this->base().'/students', ['student_ids' => [$student->id]])->assertForbidden();
        $this->tournament->update(['is_online_kata' => true]);
        $this->postJson($this->base().'/students/online-kata', [])->assertForbidden();
        $this->getJson($this->base())->assertJsonPath('tournament.can_attach_students', false);
    }

    public function test_exact_commission_start_and_generation_boundaries(): void
    {
        $access = app(CoachTournamentAccess::class);
        $deadline = now()->addHour();
        $this->tournament->update(['date_commission' => $deadline, 'date_finish' => now()->addHours(2)]);
        $this->assertTrue($access->canManage($this->coach, $this->tournament->fresh()));
        $this->travelTo($deadline);
        $this->assertTrue($access->canManage($this->coach, $this->tournament->fresh()));
        $this->travel(1)->seconds();
        $this->assertFalse($access->canManage($this->coach, $this->tournament->fresh()));
        $this->tournament->update(['date_commission' => now()->addDays(3), 'date' => today()]);
        $this->assertFalse($access->canManage($this->coach, $this->tournament->fresh()));
        $this->tournament->update(['date' => today()->addDays(2), 'date_finish' => now()->subSecond()]);
        $this->assertFalse($access->canManage($this->coach, $this->tournament->fresh()));
        $this->assertSame(now()->subSecond()->format('H:i:s'), $this->tournament->fresh()->date_finish->format('H:i:s'));
    }

    public function test_generated_online_kata_blocks_enrollment_and_detach(): void
    {
        $this->tournament->update(['is_online_kata' => true]);
        $student = $this->student();
        $list = $this->list();
        $this->membership($student, $list);
        foreach (['pools', 'kata_pools'] as $table) {
            DB::table($table)->insert(['tournament_id' => $this->tournament->id, 'list_id' => $list->id, 'round' => '1']);
            $this->getJson($this->base())->assertJsonPath('tournament.can_attach_students', false);
            $this->getJson($this->base().'/students/attach-options')->assertForbidden();
            $this->postJson($this->base().'/students', ['student_ids' => [$student->id]])->assertForbidden();
            $this->postJson($this->base().'/students/online-kata', ['student_id' => $student->id])->assertForbidden();
            $this->deleteJson($this->base().'/students/'.$this->entry($student))->assertForbidden();
            $this->assertDatabaseCount('tournament_student_lists', 1);
            DB::table($table)->delete();
        }
    }

    public function test_coach_adds_personal_entries_to_generated_lists_without_changing_draws_or_results(): void
    {
        foreach (['kumite', 'flag', 'personal'] as $type) {
            $this->tournament->update(['tournament_type' => $type === 'kumite' ? Tournament::KUMITE : Tournament::KATA,
                'tournament_type_kata' => $type === 'flag' ? Tournament::FLAG_SYSTEM : Tournament::POINT_SYSTEM]);
            $table = $type === 'personal' ? 'kata_pools' : 'pools';
            $list = $this->list($type);
            $original = $this->student();
            $this->membership($original, $list);
            DB::table($table)->insert(['tournament_id' => $this->tournament->id, 'list_id' => $list->id,
                'student_id' => $original->id, 'round' => '1']);

            foreach ([false, true] as $withResults) {
                if ($withResults) {
                    DB::table($table)->where('list_id', $list->id)->update($table === 'pools'
                        ? ['winner_id' => $original->id, 'student_wazari_count' => 2]
                        : ['total_score' => 24]);
                }
                $before = DB::table($table)->orderBy('id')->get()->toJson();
                $student = $this->student();
                $this->getJson($this->base())->assertOk()
                    ->assertJsonPath('tournament.can_attach_students', true)
                    ->assertJsonPath('tournament.can_detach_students', false);
                $this->getJson($this->base().'/students/attach-options')->assertOk()
                    ->assertJsonFragment(['id' => $student->id]);
                $this->postJson($this->base().'/students', ['student_ids' => [$student->id]])->assertOk()
                    ->assertJsonPath('attached.0.list_tournament_id', $list->id);
                $this->assertDatabaseHas('tournament_student_lists', ['student_id' => $student->id, 'list_tournament_id' => $list->id, 'group_id' => null]);
                $this->getJson($this->base().'/students')->assertOk()->assertJsonFragment(['id' => $student->id]);
                $this->postJson($this->base().'/students', ['student_ids' => [$student->id]])->assertUnprocessable();
                $this->deleteJson($this->base().'/students/'.$this->entry($student))->assertForbidden();
                $this->assertSame($before, DB::table($table)->orderBy('id')->get()->toJson());
                $this->assertDatabaseHas('activity_log', ['event' => 'mobile.tournament.student.attached', 'subject_id' => $this->entry($student)]);
            }
        }
    }

    public function test_generated_draw_does_not_bypass_enrollment_deadline_permissions_or_ownership(): void
    {
        $this->tournament->update(['tournament_type' => Tournament::KUMITE]);
        $list = $this->list('kumite');
        DB::table('pools')->insert(['tournament_id' => $this->tournament->id, 'list_id' => $list->id, 'round' => '1']);
        $student = $this->student();
        $foreign = $this->user('Student');
        $this->postJson($this->base().'/students', ['student_ids' => [$student->id, $foreign->id]])->assertUnprocessable();
        $this->assertDatabaseCount('student_tournaments', 0);
        $this->org->update(['can_edit_coaches' => false]);
        $this->getJson($this->base())->assertJsonPath('tournament.can_attach_students', false);
        $this->getJson($this->base().'/students/attach-options')->assertForbidden();
        $this->postJson($this->base().'/students', ['student_ids' => [$student->id]])->assertForbidden();
        $this->org->update(['can_edit_coaches' => true]);
        $this->tournament->update(['date_commission' => now()->subSecond()]);
        $this->getJson($this->base())->assertJsonPath('tournament.can_attach_students', false);
        $this->postJson($this->base().'/students', ['student_ids' => [$student->id]])->assertForbidden();
        $this->assertDatabaseCount('tournament_student_lists', 0);
    }

    public function test_paginated_options_selection_validation_and_duplicate_rejection(): void
    {
        foreach (range(1, 25) as $i) {
            $this->student()->update(['last_name' => sprintf('Student%02d', $i)]);
        }
        $this->getJson($this->base().'/students/attach-options')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 25);
        $id = $this->getJson($this->base().'/students/attach-options?page=2')->assertOk()->assertJsonCount(5, 'data')->json('data.4.id');
        $this->getJson($this->base().'/students/attach-options?search=Student25%20Alex')->assertOk()->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.club', 'DOJO');
        $foreign = $this->user('Student');
        $this->postJson($this->base().'/students', ['student_ids' => [$id, $foreign->id]])->assertUnprocessable()->assertJsonValidationErrors('student_ids.1');
        $this->assertDatabaseCount('student_tournaments', 0);
        $this->postJson($this->base().'/students', ['student_ids' => [$id]])->assertOk()->assertJsonCount(1, 'attached');
        $this->postJson($this->base().'/students', ['student_ids' => [$id]])->assertUnprocessable();
        $this->getJson($this->base().'/students/attach-options?search=Student25')->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('tournament_student_lists', 1);
        $this->assertDatabaseHas('activity_log', ['event' => 'mobile.tournament.student.attached', 'causer_id' => $this->coach->id]);
    }

    public function test_group_application_does_not_block_personal_and_detach_preserves_group(): void
    {
        $student = $this->student();
        $group = $this->membership($student, $this->list('group'), (string) Str::uuid());
        $this->getJson($this->base().'/students/attach-options')->assertJsonPath('meta.total', 1);
        $this->postJson($this->base().'/students', ['student_ids' => [$student->id]])->assertOk()->assertJsonCount(1, 'attached');
        $this->assertDatabaseCount('tournament_student_lists', 2);
        $this->getJson($this->base().'/students')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.can_detach', true);
        $this->deleteJson($this->base().'/students/'.$this->entry($student))->assertOk();
        $this->assertDatabaseCount('tournament_student_lists', 1);
        $this->assertNotNull($group->fresh());
        $this->getJson($this->base().'/students')->assertJsonPath('data.0.can_detach', false);
        $this->deleteJson($this->base().'/students/'.$this->entry($student))->assertUnprocessable();
    }

    public function test_online_kata_cannot_skip_video_and_payment(): void
    {
        $this->tournament->update(['is_online_kata' => true]);
        $student = $this->student();
        $this->postJson($this->base().'/students', ['student_ids' => [$student->id]])->assertUnprocessable()->assertJsonValidationErrors('student_ids');
        $this->postJson($this->base().'/students/online-kata', ['student_id' => $student->id])->assertUnprocessable()->assertJsonValidationErrors('video');
        $this->assertDatabaseCount('student_tournaments', 0);
    }

    public function test_documents_and_officials_are_authorized_and_service_files_are_not_exposed(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('tournaments/regulation.pdf', '%PDF-test');
        $this->tournament->update(['chief_judge' => 'Judge', 'chief_secretary' => 'Secretary', 'regulation_document' => 'tournaments/regulation.pdf', 'logo_report' => 'private-logo.png']);
        $this->getJson($this->base())->assertOk()->assertJsonPath('tournament.chief_judge', 'Judge')->assertJsonPath('tournament.chief_secretary', 'Secretary')->assertJsonCount(1, 'tournament.documents')->assertJsonMissingPath('tournament.logo_report');
        $this->get($this->base().'/documents/regulation_document')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->get($this->base().'/documents/logo_report')->assertNotFound();
    }

    public function test_deleted_users_coaches_and_duplicate_enrollments_do_not_inflate_lists(): void
    {
        $list = $this->list();
        $student = $this->student();
        $this->membership($student, $list);
        StudentTournament::create(['student_id' => $student->id, 'tournament_id' => $this->tournament->id]);
        $deleted = $this->student();
        $this->membership($deleted, $list);
        $deleted->delete();
        $other = $this->user('Coach');
        $orphan = $this->user('Student', ['coach_id' => $other->id]);
        $this->membership($orphan, $list);
        DB::table('tournament_treners')->insert(['tournament_id' => $this->tournament->id, 'trener_id' => $other->id]);
        $other->delete();
        $this->getJson($this->base().'/students')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.club', 'DOJO');
        $this->getJson($this->base().'/coaches')->assertOk()->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.students_count', 1);
        $this->getJson('/api/mobile/championships/'.$this->champ->id.'?ownership=all&status=all')->assertOk()->assertJsonPath('data.0.students_count', 1)->assertJsonPath('data.0.trainers_count', 1);
    }

    public function test_mobile_assignment_and_display_use_age_on_commission_day(): void
    {
        $student = $this->student();
        $student->update(['birthday' => '2016-09-06']);
        $list = $this->list();
        $this->getJson($this->base().'/students/attach-options')->assertOk()->assertJsonPath('data.0.age', 10);
        $this->postJson($this->base().'/students', ['student_ids' => [$student->id]])->assertOk();
        $this->assertDatabaseHas('tournament_student_lists', ['student_id' => $student->id, 'list_tournament_id' => $list->id]);
        $this->getJson($this->base().'/students')->assertOk()->assertJsonPath('data.0.age', 10);
        $this->getJson($this->base().'/lists/'.$list->id.'/members')->assertOk()->assertJsonPath('data.0.age', 10);
    }

    public function test_assignment_boundaries_age_on_commission_first_match_and_unique_fallback(): void
    {
        $student = $this->student();
        $service = app(StudentTournamentListAssignmentService::class);
        $this->tournament->update(['tournament_type' => Tournament::KUMITE]);
        $list = $this->list('kumite', ['age_from' => 10, 'age_to' => 10, 'weight_from' => 30, 'weight_to' => 35, 'rang_from' => 8, 'rang_to' => 4, 'gender' => 'm']);
        $second = $this->list('kumite');
        $this->assertSame($list->id, $service->bestListFor($student, $this->tournament->fresh())->id);
        $student->update(['weight' => 35, 'rang' => '4 кю']);
        $this->assertSame($list->id, $service->bestListFor($student, $this->tournament->fresh())->id);
        $student->update(['birthday' => '2016-09-06']);
        $this->assertSame($list->id, $service->bestListFor($student, $this->tournament->fresh())->id);
        $student->update(['birthday' => '2016-09-07']);
        $fallback = $service->bestListFor($student, $this->tournament->fresh());
        $this->assertNotContains($fallback->id, [$list->id, $second->id]);
        $this->assertSame($fallback->id, $service->bestListFor($student, $this->tournament->fresh())->id);
        $this->travelTo(now()->addDays(3));
        $this->assertSame($fallback->id, $service->bestListFor($student->fresh(), $this->tournament->fresh())->id);
        $student->update(['birthday' => '2016-09-06']);
        $this->assertSame($list->id, $service->bestListFor($student->fresh(), $this->tournament->fresh())->id);
        $student->update(['gender' => 'f']);
        $this->assertSame($second->id, $service->bestListFor($student->fresh(), $this->tournament->fresh())->id);
    }

    public function test_online_options_allow_group_member_and_revalidate_owner_before_payment(): void
    {
        Storage::fake('protected');
        $this->tournament->update(['is_online_kata' => true]);
        $student = $this->student();
        $this->membership($student, $this->list('group'), (string) Str::uuid());
        $category = DB::table('education_klass_categories')->insertGetId(['name' => 'Kata', 'price' => 0]);
        $this->mock(OnlineKataPaymentService::class, function ($mock) use ($student): void {
            $mock->shouldReceive('createPayment')->once()->withArgs(fn ($tournament, $selected) => $selected->id === $student->id)
                ->andReturn(['payment_id' => 'test-payment', 'payment_url' => 'https://example.test/payment']);
        });
        $this->getJson($this->base().'/students/attach-options')->assertJsonPath('data.0.id', $student->id);
        $foreign = $this->user('Student');
        foreach ([$foreign->id => 422, $student->id => 200] as $id => $status) {
            $this->post($this->base().'/students/online-kata', [
                'student_id' => $id, 'online_kata_first_round_category_id' => $category,
                'video' => UploadedFile::fake()->create('kata.mp4', 1, 'video/mp4'),
            ], ['Accept' => 'application/json'])->assertStatus($status);
        }
        $this->assertDatabaseCount('tournament_student_lists', 1);
    }

    public function test_foreign_detach_and_wrong_championship_are_rejected_and_attach_rolls_back(): void
    {
        $student = $this->user('Student');
        $this->membership($student, $this->list());
        $this->deleteJson($this->base().'/students/'.$this->entry($student))->assertForbidden();
        $otherChamp = Championship::create(['name' => 'Another', 'banner' => 'banner.jpg', 'organization_id' => $this->org->id]);
        $this->getJson('/api/mobile/championships/'.$otherChamp->id.'/tournaments/'.$this->tournament->id)->assertNotFound();
        $own = $this->student();
        DB::statement("CREATE TRIGGER reject_membership BEFORE INSERT ON tournament_student_lists BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        try {
            $this->postJson($this->base().'/students', ['student_ids' => [$own->id]])->assertStatus(500);
        } finally {
            DB::statement('DROP TRIGGER reject_membership');
        }
        $this->assertDatabaseMissing('student_tournaments', ['student_id' => $own->id]);
        $this->assertDatabaseMissing('activity_log', ['event' => 'mobile.tournament.student.attached']);
    }
}
