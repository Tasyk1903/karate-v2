<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\Examination;
use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\MobileAccessToken;
use App\Models\StudentTournament;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\TournamentStudentList;
use App\Models\User;
use App\Services\ProtectedMedia;
use App\Services\Team\TeamActivity;
use App\Services\Tournaments\PanelTournamentVisibility;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesProfileTournament;
use Tests\TestCase;

class StudentAccountTest extends TestCase
{
    use CreatesProfileTournament;
    use RefreshDatabase;

    private User $organization;

    private User $coach;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Organization', 'Secretary', 'Coach', 'Student'] as $role) {
            $id = DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $role]);
        }
        $this->organization = $this->user('Organization', ['can_edit_students' => true]);
        $this->coach = $this->user('Coach', ['organization_id' => $this->organization->id, 'club' => 'Coach club']);
        $this->student = $this->user('Student', ['coach_id' => $this->coach->id, 'organization_id' => $this->organization->id,
            'birthday' => '2015-01-02', 'gender' => 'm', 'rang' => '5 кю', 'patronymic' => 'Ivanovich', 'weight' => 35, 'club' => 'Wrong club']);
        $this->acceptMobileAgreements($this->student);
        $this->actingAs($this->student);
    }

    private function user(string $role, array $data = []): User
    {
        return User::forceCreate($data + ['first_name' => 'Ivan', 'last_name' => 'Example', 'email' => uniqid().'@example.test',
            'password' => Hash::make('password'), 'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
    }

    public function test_profile_round_trip_and_private_access(): void
    {
        $profile = $this->getJson('/api/panel/student/profile')->assertOk()
            ->assertJsonPath('student.club', 'Coach club')->assertJsonPath('profile.patronymic', 'Ivanovich')
            ->assertJsonPath('can_confirm_documents', false)->json('profile');
        $this->postJson('/api/panel/student/profile', $profile)->assertOk()->assertJsonPath('profile.patronymic', 'Ivanovich');
        $foreign = $this->user('Student', ['coach_id' => $this->coach->id, 'organization_id' => $this->organization->id, 'passport' => 'passport/other.png']);
        $this->getJson('/api/panel/team/students/'.$foreign->id)->assertForbidden();
        $this->getJson('/api/panel/team/students/'.$foreign->id.'/history?kind=wins')->assertForbidden();
        $this->getJson(app(ProtectedMedia::class)->documentUrl($foreign, 'passport'))->assertForbidden();
        $this->putJson('/api/panel/team/students/'.$this->student->id.'/documents/passport', ['is_success_passport' => true])->assertForbidden();
        $this->postJson('/api/panel/student/profile', ['is_success_passport' => true])->assertUnprocessable();
    }

    public function test_mobile_self_profile_preserves_identity_files_period_and_deletes_only_with_password(): void
    {
        MobileAccessToken::forceCreate(['user_id' => $this->student->id, 'name' => 'test', 'token' => hash('sha256', 'self-profile'), 'expires_at' => now()->addMonth()]);
        $this->withToken('self-profile');
        $path = '/api/mobile/students/'.$this->student->id;
        $profile = $this->getJson($path)->assertOk()->assertJsonPath('student.patronymic', 'Ivanovich')->json('student');
        $this->postJson($path, array_intersect_key($profile, array_flip(['first_name', 'last_name', 'patronymic', 'gender', 'email', 'birthday', 'rang', 'weight'])))
            ->assertOk()->assertJsonPath('student.patronymic', 'Ivanovich');
        $this->student->forceFill(['rang' => '9 кю', 'birthday' => today()->subYears(7)->toDateString(), 'competitive_record_starts_at' => null])->save();
        $this->postJson($path, ['rang' => '8 кю'])->assertOk();
        $this->assertSame(today()->addYear()->toDateString(), Carbon::parse($this->student->fresh()->competitive_record_starts_at)->toDateString());
        $this->postJson($path, ['birthday' => today()->subYears(10)->toDateString()])->assertOk();
        $this->assertSame(today()->toDateString(), Carbon::parse($this->student->fresh()->competitive_record_starts_at)->toDateString());
        $this->student->forceFill(['competitive_record_starts_at' => '2020-01-01'])->save();
        $this->postJson($path, ['birthday' => '2010-01-01'])->assertOk();
        $this->assertSame('2020-01-01', Carbon::parse($this->student->fresh()->competitive_record_starts_at)->toDateString());
        foreach (ProtectedMedia::DOCUMENTS as $field) {
            $this->post($path, [$field => UploadedFile::fake()->image($field.'.png')], ['Accept' => 'application/json'])->assertOk();
            $file = $this->student->fresh()->$field;
            $this->get('/api/mobile/files/users/'.$this->student->id.'/'.$field)->assertOk();
            $this->postJson($path, ['remove_documents' => [$field]])->assertOk();
            Storage::disk('protected')->assertMissing($file);
        }
        $this->post($path, ['avatar' => UploadedFile::fake()->image('avatar.png')], ['Accept' => 'application/json'])->assertOk();
        $avatar = $this->student->fresh()->avatar;
        $this->postJson($path, ['remove_avatar' => true])->assertOk();
        Storage::disk('public')->assertMissing($avatar);
        $this->postJson('/api/mobile/account/delete', ['confirmed' => true, 'password' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/mobile/account/delete', ['confirmed' => true, 'password' => 'password'])->assertOk();
        $this->assertSoftDeleted('users', ['id' => $this->student->id]);
        $this->getJson('/api/mobile/auth/user')->assertUnauthorized();
    }

    public function test_mobile_restore_requires_email_proof_is_single_use_and_does_not_undo_admin_deletion(): void
    {
        Mail::fake();
        $this->student->delete();
        TeamActivity::record($this->student, 'mobile.account.deleted', User::class, $this->student->id, ['self' => true]);
        $this->postJson('/api/mobile/auth/restore', ['email' => $this->student->email])->assertOk();
        $this->assertSoftDeleted('users', ['id' => $this->student->id]);
        DB::table('account_restore_challenges')->where('user_id', $this->student->id)->update(['code_hash' => Hash::make('123456')]);
        $data = ['email' => $this->student->email, 'code' => '000000', 'password' => 'new-password', 'password_confirmation' => 'new-password'];
        $this->postJson('/api/mobile/auth/restore/confirm', $data)->assertUnprocessable();
        $this->assertDatabaseHas('account_restore_challenges', ['user_id' => $this->student->id, 'attempts' => 1]);
        $data['code'] = '123456';
        $this->postJson('/api/mobile/auth/restore/confirm', $data)->assertOk();
        $this->assertTrue(Hash::check('new-password', User::findOrFail($this->student->id)->password));
        $this->postJson('/api/mobile/auth/restore/confirm', $data)->assertUnprocessable();
        $this->student->refresh()->delete();
        TeamActivity::record($this->organization, 'user.deleted', User::class, $this->student->id, []);
        $this->postJson('/api/mobile/auth/restore', ['email' => $this->student->email])->assertOk();
        $this->assertDatabaseMissing('account_restore_challenges', ['user_id' => $this->student->id]);
    }

    public function test_student_restrictions_do_not_use_coach_permission(): void
    {
        $this->organization->forceFill(['can_edit_students' => false, 'can_edit_coaches' => true])->save();
        $this->profileTournament($this->organization, $this->student);
        $this->getJson('/api/panel/student/profile')->assertJsonPath('capabilities.rang', false);
        $this->postJson('/api/panel/student/profile', ['rang' => '4 кю'])->assertUnprocessable();
        $this->postJson('/api/panel/student/profile', ['birthday' => '2014-01-01'])->assertUnprocessable();
        $this->postJson('/api/panel/student/profile', ['rang' => '5 кю', 'birthday' => '02.01.2015', 'weight' => 35])->assertOk();
        $this->postJson('/api/panel/student/profile', ['email' => $this->coach->email])->assertUnprocessable();
        $this->postJson('/api/panel/student/profile', ['birthday' => '31.02.2015'])->assertUnprocessable();
    }

    public function test_file_replacement_removal_and_rollback(): void
    {
        Storage::disk('protected')->put('passport/old.png', 'old');
        $this->student->forceFill(['passport' => 'passport/old.png', 'is_success_passport' => true])->save();
        $this->post('/api/panel/student/profile', ['passport' => UploadedFile::fake()->image('new.png'), 'avatar' => UploadedFile::fake()->image('avatar.png')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('protected')->assertMissing('passport/old.png');
        $this->assertFalse((bool) $this->student->fresh()->is_success_passport);
        $avatar = $this->student->fresh()->avatar;
        Storage::disk('public')->assertExists($avatar);
        $this->getJson(app(ProtectedMedia::class)->documentUrl($this->student->fresh(), 'passport'))->assertOk();
        $this->postJson('/api/panel/student/profile', ['remove_documents' => ['passport'], 'remove_avatar' => true])->assertOk();
        Storage::disk('public')->assertMissing($avatar);
        $this->assertSame([], Storage::disk('protected')->allFiles());
        $this->assertDatabaseHas('activity_log', ['event' => 'student.profile.updated', 'causer_id' => $this->student->id]);
        $this->organization->forceFill(['can_edit_students' => false])->save();
        $this->profileTournament($this->organization, $this->student);
        $this->post('/api/panel/student/profile', ['rang' => '4 кю', 'passport' => UploadedFile::fake()->image('failed.png'), 'avatar' => UploadedFile::fake()->image('failed.png')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame([], Storage::disk('protected')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_agreements_gate_and_notifications_are_personal(): void
    {
        DB::table('agreement_acceptances')->where('user_id', $this->student->id)->delete();
        $this->getJson('/api/panel/student/profile')->assertStatus(409);
        $this->getJson('/api/panel/account/agreements')->assertOk();
        $this->acceptMobileAgreements($this->student);
        $alert = DB::table('user_alerts')->insertGetId(['message' => '<p>Hello</p>', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('user_alert_user')->insert(['user_id' => $this->student->id, 'user_alert_id' => $alert, 'created_at' => now(), 'updated_at' => now()]);
        $this->getJson('/api/panel/account/notifications')->assertOk()->assertJsonPath('unread', 1);
        $this->postJson('/api/panel/account/notifications/'.$alert.'/read')->assertOk()->assertJsonPath('unread', 0);
        $this->getJson('/api/panel/account/profile')->assertForbidden();
    }

    public function test_delete_requires_password_and_revokes_session_without_destroying_history(): void
    {
        $this->deleteJson('/api/panel/student/account', ['confirmed' => true, 'password' => 'wrong'])->assertUnprocessable();
        $this->assertNotSoftDeleted('users', ['id' => $this->student->id]);
        $this->deleteJson('/api/panel/student/account', ['confirmed' => true, 'password' => 'password'])->assertOk();
        $this->assertSoftDeleted('users', ['id' => $this->student->id]);
        $this->getJson('/api/panel/student/profile')->assertUnauthorized();
        $this->assertDatabaseHas('activity_log', ['event' => 'student.account.deleted', 'causer_id' => $this->student->id]);
    }

    public function test_exam_roster_self_enrollment_and_no_export_or_foreign_mutations(): void
    {
        $exam = Examination::forceCreate(['organization_id' => $this->organization->id, 'name' => 'Grading', 'city' => 'City', 'date' => today(), 'receiving' => 'Master']);
        $base = '/api/panel/examinations/'.$exam->id;
        $this->getJson($base)->assertForbidden();
        $this->coach->forceFill(['can_attach_to_examination_for_students' => true])->save();
        $other = $this->user('Student', ['coach_id' => $this->coach->id]);
        $exam->students()->attach($other->id);
        $this->getJson($base)->assertOk()->assertJsonPath('item.can_attach_self', true)->assertJsonPath('item.can_export', false);
        $this->getJson($base.'/students')->assertOk()->assertJsonPath('items.meta.total', 1);
        $this->postJson($base.'/attach-self', ['student_id' => $other->id])->assertOk()->assertJsonPath('attached.0', $this->student->id);
        $this->getJson($base)->assertJsonPath('item.can_attach_self', false);
        $this->getJson($base.'/students/export')->assertForbidden();
        $this->deleteJson($base.'/students/'.$other->id)->assertForbidden();
        $this->postJson($base.'/students', ['student_ids' => [$other->id]])->assertForbidden();
        $this->deleteJson($base.'/students/'.$this->student->id)->assertOk()->assertJsonPath('detached', true);
        $this->assertDatabaseHas('examination_student', ['examination_id' => $exam->id, 'student_id' => $other->id]);
        $this->assertDatabaseMissing('examination_student', ['examination_id' => $exam->id, 'student_id' => $this->student->id]);
        $this->coach->forceFill(['can_attach_to_examination_for_students' => false])->save();
        $this->postJson($base.'/attach-self')->assertForbidden();
    }

    public function test_education_catalog_permissions_partition_pagination_and_private_stream(): void
    {
        foreach (['view_', 'view_any_'] as $prefix) {
            $id = DB::table('permissions')->insertGetId(['name' => $prefix.'education::kata::category', 'guard_name' => 'web']);
            DB::table('role_has_permissions')->insert(['role_id' => $this->student->role_id, 'permission_id' => $id]);
        }
        $this->getJson('/api/panel/student/education')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/panel/student/education/catalog/competition')->assertForbidden();
        $lastCategory = null;
        for ($i = 0; $i < 21; $i++) {
            $lastCategory = DB::table('education_kata_categories')->insertGetId(['name' => 'Kihon '.$i, 'type' => 'kihon']);
        }
        $base = '/api/panel/student/education';
        $this->getJson($base.'/catalog/kihon?page=2')->assertJsonCount(1, 'data')->assertJsonPath('total', 21);
        $video = DB::table('education_kata_videos')->insertGetId(['education_kata_category_id' => $lastCategory, 'title' => 'Lesson', 'path' => 'legacy/lesson.mp4']);
        Storage::disk('protected')->put('legacy/lesson.mp4', '0123456789');
        $row = $this->getJson($base.'/catalog/kihon/'.$lastCategory)->assertOk()->json('data.0');
        $this->assertArrayNotHasKey('path', $row);
        $this->get($row['video_url'], ['Range' => 'bytes=0-3'])->assertStatus(206)->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($base.'/catalog/ido_geiko/'.$lastCategory)->assertNotFound();
        $this->getJson($base.'/files/ido_geiko/'.$video.'/video')->assertNotFound();
        $this->postJson($base.'/catalog/kihon/'.$lastCategory, ['title' => 'New'])->assertStatus(405);
        DB::table('role_has_permissions')->where('role_id', $this->student->role_id)->delete();
        $this->get($row['video_url'])->assertForbidden();
    }

    private function tournament(): Tournament
    {
        $championship = Championship::forceCreate(['organization_id' => $this->organization->id, 'name' => 'Championship', 'banner' => 'banner.png']);
        $tournament = Tournament::forceCreate(['name' => 'Kata', 'organization_id' => $this->organization->id, 'championship_id' => $championship->id,
            'tournament_type' => Tournament::KATA, 'tournament_type_kata' => Tournament::POINT_SYSTEM, 'age_from' => 0, 'age_to' => 100, 'tatami' => 1, 'price' => 0,
            'date' => now()->addDays(2), 'date_commission' => now()->addDay(), 'date_finish' => now()->addDays(3), 'address' => 'City']);
        DB::table('tournament_treners')->insert(['tournament_id' => $tournament->id, 'trener_id' => $this->coach->id]);

        return $tournament;
    }

    private function studentList(Tournament $tournament, string $type): ListTournament
    {
        $template = TemplateStudentList::forceCreate(['user_id' => $this->organization->id, 'name' => $type, 'list_type' => 'kata', 'kata_type' => $type, 'gender' => 'all', 'age_from' => 0, 'age_to' => 100]);

        return ListTournament::forceCreate(['tournament_id' => $tournament->id, 'template_student_list_id' => $template->id]);
    }

    public function test_tournament_catalog_and_payload_respect_deleted_users_and_private_fields(): void
    {
        $tournament = $this->tournament();
        $base = '/api/panel/tournaments/'.$tournament->championship_id.'/items/'.$tournament->id;
        $other = $this->user('Student', ['coach_id' => $this->coach->id, 'insurance_close_date' => '2030-01-01']);
        StudentTournament::forceCreate(['student_id' => $other->id, 'tournament_id' => $tournament->id, 'online_kata_video_path' => 'private/other.mp4']);
        $this->getJson($base)->assertOk()->assertJsonPath('detail.students.data.0.first_round_video', null)
            ->assertJsonPath('detail.students.data.0.documents_status_context', [])
            ->assertJsonMissing(['email' => $this->coach->email]);
        $other->delete();
        $this->getJson($base)->assertOk()->assertJsonCount(0, 'detail.students.data');
        $this->coach->delete();
        $this->getJson($base)->assertForbidden();
        $visibility = app(PanelTournamentVisibility::class);
        $this->assertSame(0, $visibility->championships($this->student)->count());
        $this->assertSame(0, $visibility->tournaments($this->student)->count());
    }

    public function test_self_tournament_enrollment_is_personal_idempotent_and_preserves_group(): void
    {
        $tournament = $this->tournament();
        $base = '/api/panel/tournaments/'.$tournament->championship_id.'/items/'.$tournament->id;
        $personal = $this->studentList($tournament, 'personal');
        $group = $this->studentList($tournament, 'group');
        $entry = StudentTournament::forceCreate(['student_id' => $this->student->id, 'tournament_id' => $tournament->id, 'list_tournament_id' => $group->id]);
        $member = TournamentStudentList::forceCreate(['student_id' => $this->student->id, 'list_tournament_id' => $group->id, 'group_id' => 'group-one']);
        $this->postJson($base.'/self', ['confirmed' => true])->assertForbidden();
        $this->coach->forceFill(['can_attach_to_tournaments_for_students' => true])->save();
        $this->getJson($base)->assertOk()->assertJsonPath('tournament.self_enrollment.can_attach', true);
        $this->postJson($base.'/self', ['confirmed' => true, 'student_id' => $this->coach->id])->assertOk()->assertJsonPath('self_enrollment.can_attach', false);
        $this->postJson($base.'/self', ['confirmed' => true])->assertOk();
        $this->assertDatabaseCount('tournament_student_lists', 2);
        $personalMember = TournamentStudentList::where('list_tournament_id', $personal->id)->firstOrFail();
        $this->deleteJson($base.'/self/'.$personalMember->id, ['confirmed' => true])->assertOk();
        $this->assertDatabaseHas('tournament_student_lists', ['id' => $member->id, 'group_id' => 'group-one']);
        $this->assertDatabaseHas('student_tournaments', ['id' => $entry->id]);
        $this->deleteJson($base.'/self/'.$member->id, ['confirmed' => true])->assertOk();
        $this->assertDatabaseMissing('tournament_student_lists', ['id' => $member->id]);
        $this->getJson($base.'/downloads/results')->assertForbidden();
        $this->postJson($base.'/self', ['confirmed' => true])->assertOk();
        $this->getJson('/api/panel/student/profile')->assertJsonPath('capabilities.weight', false);
        $this->postJson('/api/panel/student/profile', ['weight' => 44])->assertUnprocessable();
        $this->postJson('/api/panel/student/profile', ['weight' => 35, 'city_training' => 'Other city'])->assertOk();
        $tournament->forceFill(['date' => today()])->save();
        $this->postJson($base.'/self', ['confirmed' => true])->assertForbidden();
    }

    public function test_self_online_cannot_bypass_payment_and_only_own_final_video_is_editable(): void
    {
        $tournament = $this->tournament();
        $tournament->forceFill(['is_online_kata' => true])->save();
        $base = '/api/panel/tournaments/'.$tournament->championship_id.'/items/'.$tournament->id;
        $this->postJson($base.'/self', ['confirmed' => true])->assertUnprocessable();
        $this->assertDatabaseCount('student_tournaments', 0);
        $list = $this->studentList($tournament, 'personal');
        $entry = StudentTournament::forceCreate(['student_id' => $this->student->id, 'tournament_id' => $tournament->id, 'list_tournament_id' => $list->id, 'online_kata_first_round_video_path' => 'online-kata-videos/first.mp4']);
        TournamentStudentList::forceCreate(['student_id' => $this->student->id, 'list_tournament_id' => $list->id]);
        $pool = KataPool::forceCreate(['student_id' => $this->student->id, 'tournament_id' => $tournament->id, 'list_id' => $list->id, 'round' => 'FINAL']);
        $category = DB::table('education_klass_categories')->insertGetId(['name' => 'Kata', 'price' => 10]);
        Storage::disk('protected')->put('online-kata-videos/first.mp4', '0123456789');
        $this->get($base.'/students/'.$entry->id.'/online-kata-video/first', ['Range' => 'bytes=0-3'])->assertStatus(206);
        $this->post($base.'/kata-pools/'.$pool->id.'/final-video', ['student_id' => $this->student->id, 'category_id' => $category, 'video' => UploadedFile::fake()->create('final.mp4', 10, 'video/mp4')], ['Accept' => 'application/json'])->assertOk();
        $this->assertNotNull($entry->fresh()->online_kata_second_round_video_path);
        $this->post($base.'/kata-pools/'.$pool->id.'/final-video', ['student_id' => $this->coach->id, 'category_id' => $category, 'video' => UploadedFile::fake()->create('bad.mp4', 10, 'video/mp4')], ['Accept' => 'application/json'])->assertForbidden();
        $this->postJson($base.'/kata-pools/'.$pool->id.'/score', ['field' => 'judge1_score', 'value' => '10.0', 'original_value' => null])->assertForbidden();
        DB::table('tournament_treners')->where('tournament_id', $tournament->id)->delete();
        $this->getJson($base)->assertForbidden();
        $this->get($base.'/students/'.$entry->id.'/online-kata-video/first')->assertForbidden();
    }
}
