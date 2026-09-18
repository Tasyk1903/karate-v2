<?php

namespace Tests\Feature;

use App\Models\Examination;
use App\Models\MobileAccessToken;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\TestCase;

class MobileExaminationsTest extends TestCase
{
    use RefreshDatabase;

    private User $org;

    private User $coach;

    private Examination $exam;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        $this->travelTo(now()->setDate(2026, 9, 8)->setTime(12, 0));
        foreach (['Organization', 'Coach', 'Student'] as $name) {
            $id = DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $name]);
        }
        $this->org = $this->user('Organization');
        $this->coach = $this->user('Coach', ['organization_id' => $this->org->id, 'club' => 'Coach club']);
        $this->exam = $this->exam($this->org);
        MobileAccessToken::create(['user_id' => $this->coach->id, 'name' => 'test', 'token' => hash('sha256', 'exam-test'), 'expires_at' => now()->addMonth()]);
        $this->acceptMobileAgreements($this->coach);
        $this->withToken('exam-test');
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function user(string $role, array $extra = []): User
    {
        return User::create($extra + ['first_name' => 'Alex', 'last_name' => 'Student',
            'email' => Str::uuid().'@example.test', 'password' => 'password',
            'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
    }

    private function student(?User $coach = null, array $extra = []): User
    {
        $coach ??= $this->coach;

        return $this->user('Student', $extra + ['coach_id' => $coach->id, 'organization_id' => $coach->organization_id,
            'birthday' => '2015-01-01', 'weight' => 35, 'rang' => '5 кю', 'club' => 'Wrong student club']);
    }

    private function exam(User $org): Examination
    {
        return Examination::create(['name' => 'Kyu test', 'city' => 'Moscow', 'receiving' => 'Examiner',
            'date' => now()->addMonth(), 'organization_id' => $org->id]);
    }

    private function path(?Examination $exam = null): string
    {
        return '/api/mobile/examinations/'.($exam ?? $this->exam)->id;
    }

    public function test_exam_scope_and_every_direct_route_reject_foreign_organization(): void
    {
        $foreign = $this->exam($this->user('Organization'));
        $student = $this->student();
        $this->getJson('/api/mobile/examinations')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $this->exam->id);
        foreach (['', '/students', '/attach-options', '/students/export'] as $suffix) {
            $this->getJson($this->path($foreign).$suffix)->assertForbidden();
        }
        $this->postJson($this->path($foreign).'/students', ['student_ids' => [$student->id]])->assertForbidden();
        $this->deleteJson($this->path($foreign).'/students/'.$student->id)->assertForbidden();
        $this->coach->update(['organization_id' => null]);
        $this->getJson('/api/mobile/examinations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson($this->path())->assertForbidden();
    }

    public function test_participants_and_excel_include_other_coaches_but_match_organization_and_filter(): void
    {
        $other = $this->user('Coach', ['organization_id' => $this->org->id, 'club' => 'Other club']);
        $outside = $this->user('Coach', ['organization_id' => $this->user('Organization')->id]);
        $own = $this->student(extra: ['last_name' => 'Alpha']);
        $peer = $this->student($other, ['last_name' => 'Beta']);
        $foreign = $this->student($outside, ['last_name' => 'Outside']);
        $deleted = $this->student(extra: ['last_name' => 'Deleted']);
        $this->exam->students()->attach([$own->id, $peer->id, $foreign->id, $deleted->id]);
        $deleted->delete();
        $this->getJson($this->path())->assertOk()->assertJsonPath('item.students_count', 2);
        $this->getJson($this->path().'/students')->assertOk()->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.can_detach', true)->assertJsonPath('data.1.can_detach', false)
            ->assertJsonPath('data.0.club', 'Coach club')->assertJsonPath('data.1.club', 'Other club');
        $this->getJson($this->path().'/students?coach_id='.$other->id)->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $peer->id);
        $this->getJson($this->path().'/students?search=Alex%20Beta')->assertOk()->assertJsonCount(1, 'data');
        foreach (['/students', '/students/export'] as $suffix) {
            $this->getJson($this->path().$suffix.'?coach_id='.$outside->id)->assertForbidden();
        }
        foreach (['' => ['Alpha Alex', 'Beta Alex'], '?coach_id='.$other->id => ['Beta Alex']] as $query => $names) {
            $response = $this->get($this->path().'/students/export'.$query)->assertOk();
            $sheet = IOFactory::load($response->baseResponse->getFile()->getPathname())->getActiveSheet();
            $this->assertSame($names, array_map(fn ($row) => $row[1], array_values(array_filter(
                $sheet->rangeToArray('A7:L'.max(7, $sheet->getHighestRow())), fn ($row) => $row[1] !== null))));
            $this->assertSame(5, $sheet->getCell('F7')->getValue());
            $this->assertSame(4, $sheet->getCell('G7')->getValue());
        }
        $this->assertSame(2, DB::table('activity_log')->where('event', 'mobile.examination.students.exported')->count());
    }

    public function test_all_options_are_paginated_searchable_and_exclude_attached_deleted_and_foreign_students(): void
    {
        $ids = [];
        for ($i = 1; $i <= 26; $i++) {
            $ids[] = $this->student(extra: ['last_name' => sprintf('Student%02d', $i)])->id;
        }
        $this->exam->students()->attach($ids[0]);
        $deleted = $this->student();
        $deleted->delete();
        $this->student($this->user('Coach', ['organization_id' => $this->org->id]));
        $this->student(extra: ['organization_id' => $this->user('Organization')->id]);
        $this->getJson($this->path().'/attach-options')->assertOk()->assertJsonCount(20, 'data')
            ->assertJsonPath('meta.total', 25)->assertJsonPath('meta.last_page', 2)
            ->assertJsonPath('data.0.age_years', 11)->assertJsonPath('data.0.club', 'Coach club');
        $this->getJson($this->path().'/attach-options?page=2')->assertOk()->assertJsonCount(5, 'data')->assertJsonPath('data.4.id', $ids[25]);
        $this->getJson($this->path().'/attach-options?search=Alex%20Student26')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $ids[25]);
        $this->getJson($this->path().'/attach-options?page=0')->assertUnprocessable();
    }

    public function test_attach_and_detach_are_idempotent_audited_and_update_counts(): void
    {
        $a = $this->student();
        $b = $this->student();
        $this->postJson($this->path().'/students', ['student_ids' => [$a->id]])->assertOk()->assertJsonPath('attached', [$a->id]);
        $this->postJson($this->path().'/students', ['student_ids' => [$a->id]])->assertUnprocessable();
        $this->postJson($this->path().'/students', ['student_ids' => [$a->id, $b->id]])->assertOk()->assertJsonPath('attached', [$b->id]);
        $this->getJson($this->path())->assertOk()->assertJsonPath('item.students_count', 2);
        $this->deleteJson($this->path().'/students/'.$a->id)->assertOk()->assertJsonPath('detached', true);
        $this->deleteJson($this->path().'/students/'.$a->id)->assertOk()->assertJsonPath('detached', false);
        $this->getJson($this->path().'/students')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $b->id);
        $this->assertDatabaseCount('examination_student', 1);
        $logs = DB::table('activity_log')->where('event', 'like', 'mobile.examination.%')->get();
        $this->assertCount(3, $logs);
        $this->assertSame($this->coach->id, (int) $logs[0]->causer_id);
        $this->assertSame([$a->id], json_decode($logs[0]->properties, true)['selected_ids']);
        $this->assertSame($a->id, json_decode($logs[2]->properties, true)['old']['students'][0]['id']);
    }

    public function test_invalid_selection_is_rejected_atomically_without_empty_success(): void
    {
        $own = $this->student();
        $other = $this->student($this->user('Coach', ['organization_id' => $this->org->id]));
        $notStudent = $this->user('Coach', ['coach_id' => $this->coach->id, 'organization_id' => $this->org->id]);
        $deleted = $this->student();
        $deleted->delete();
        foreach ([[$other->id], [$own->id, $other->id], [999999], [$notStudent->id], [$deleted->id]] as $ids) {
            $this->postJson($this->path().'/students', ['student_ids' => $ids])->assertForbidden();
        }
        foreach ([[], [$own->id, $own->id], ['invalid']] as $ids) {
            $this->postJson($this->path().'/students', ['student_ids' => $ids])->assertUnprocessable();
        }
        $this->assertDatabaseCount('examination_student', 0);
        $this->assertSame(0, DB::table('activity_log')->where('event', 'like', 'mobile.examination.%')->count());
        $this->exam->students()->attach($other->id);
        $this->deleteJson($this->path().'/students/'.$other->id)->assertForbidden();
        $this->assertDatabaseHas('examination_student', ['student_id' => $other->id]);
    }

    public function test_coach_enrollment_is_not_controlled_by_student_self_enrollment_flag_or_tournament_deadlines(): void
    {
        $this->coach->update(['can_attach_to_examination_for_students' => false]);
        $this->exam->update(['date' => now()->subDay()]);
        $student = $this->student();
        $this->postJson($this->path().'/students', ['student_ids' => [$student->id]])->assertOk();
        $this->deleteJson($this->path().'/students/'.$student->id)->assertOk();
    }

    public function test_student_self_workflow_roster_filter_and_revoked_permission(): void
    {
        $student = $this->student();
        $other = $this->student(extra: ['last_name' => 'Other']);
        $this->exam->students()->attach($other->id);
        $this->coach->update(['can_attach_to_examination_for_students' => true]);
        $this->acceptMobileAgreements($student);
        MobileAccessToken::create(['user_id' => $student->id, 'name' => 'test', 'token' => hash('sha256', 'self-exam'), 'expires_at' => now()->addMonth()]);
        $this->withToken('self-exam');
        $this->getJson('/api/mobile/examinations')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson($this->path())->assertOk()->assertJsonPath('item.can_attach_self', true);
        $this->getJson($this->path().'/students')->assertOk()->assertJsonPath('data.0.can_detach', false);
        $this->postJson($this->path().'/self', ['student_id' => $other->id])->assertOk()->assertJsonPath('attached', [$student->id]);
        $this->postJson($this->path().'/self')->assertOk();
        $this->assertDatabaseCount('examination_student', 2);
        $this->getJson($this->path())->assertOk()->assertJsonPath('item.can_attach_self', false)->assertJsonPath('item.students_count', 2);
        $this->getJson($this->path().'/students?coach_id='.$this->coach->id)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson($this->path().'/students/export')->assertUnauthorized();
        $this->getJson($this->path().'/attach-options')->assertUnauthorized();
        $this->deleteJson($this->path().'/students/'.$other->id)->assertForbidden();
        $this->deleteJson($this->path().'/students/'.$student->id)->assertOk()->assertJsonPath('detached', true);
        $this->assertDatabaseCount('examination_student', 1);
        $foreign = $this->exam($this->user('Organization'));
        $this->postJson($this->path($foreign).'/self')->assertForbidden();
        $this->coach->update(['can_attach_to_examination_for_students' => false]);
        $this->getJson('/api/mobile/examinations')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson($this->path().'/self')->assertForbidden();
        $this->getJson($this->path().'/students')->assertForbidden();
        $this->assertSame(2, DB::table('activity_log')->where('event', 'like', 'examination.student.self_%')->count());
    }
}
