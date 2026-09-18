<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\EducationKlassCategory;
use App\Models\EducationKlassVideo;
use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\MobileAccessToken;
use App\Models\StudentTournament;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournaments\Kata\KataScores;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileStaffTest extends TestCase
{
    use RefreshDatabase;

    private User $org;

    private User $judge;

    private User $master;

    private User $student;

    private Tournament $tournament;

    private ListTournament $list;

    private KataPool $pool;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        foreach (['Organization', 'Secretary', 'Judge', 'Master', 'Coach', 'Student'] as $name) {
            $id = DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $name]);
        }
        $this->org = $this->user('Organization');
        $this->judge = $this->user('Judge', ['organization_id' => $this->org->id, 'judge_position' => 'judge1_score']);
        $this->master = $this->user('Master', ['patronymic' => 'Middle']);
        $coach = $this->user('Coach', ['club' => 'Correct club']);
        $this->student = $this->user('Student', ['coach_id' => $coach->id, 'club' => 'Wrong club', 'rang' => '9 кю']);
        $champ = Championship::create(['name' => 'Champ', 'banner' => 'test.jpg', 'organization_id' => $this->org->id]);
        $this->tournament = Tournament::create(['name' => 'Kata', 'organization_id' => $this->org->id, 'championship_id' => $champ->id,
            'tournament_type' => 2, 'tournament_type_kata' => 2, 'is_online_kata' => true, 'age_from' => 0, 'age_to' => 100,
            'tatami' => 1, 'price' => 0, 'date_commission' => now(), 'date' => now(), 'date_finish' => now()->addDay(), 'address' => 'City']);
        $template = TemplateStudentList::create(['name' => 'Kata group', 'list_type' => 'kata', 'kata_type' => 'group', 'user_id' => $this->org->id]);
        $this->list = ListTournament::create(['tournament_id' => $this->tournament->id, 'template_student_list_id' => $template->id, 'tatami' => 'A', 'finalists_count' => 4]);
        $this->pool = KataPool::create(['list_id' => $this->list->id, 'tournament_id' => $this->tournament->id,
            'student_id' => $this->student->id, 'students' => [$this->student->id], 'round' => 'PRELIMINARY STAGE', 'referee_score' => '9.9']);
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function user(string $role, array $extra = []): User
    {
        return User::create($extra + ['first_name' => 'Alex', 'last_name' => 'Example', 'email' => Str::uuid().'@test.example',
            'password' => 'password', 'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
    }

    private function login(User $user): void
    {
        $this->acceptMobileAgreements($user);
        $token = Str::random(30);
        MobileAccessToken::create(['user_id' => $user->id, 'name' => 'test', 'token' => hash('sha256', $token), 'expires_at' => now()->addMonth()]);
        $this->withToken($token);
    }

    private function work(array $extra = []): EducationKlassVideo
    {
        $category = EducationKlassCategory::create(['name' => 'Taikyoku', 'price' => 100]);

        return EducationKlassVideo::create($extra + ['student_id' => $this->student->id, 'reviewer_id' => $this->master->id,
            'education_klass_category_id' => $category->id, 'path' => 'video/work.mp4', 'is_payment' => true, 'is_review' => false]);
    }

    public function test_role_menus_and_denied_shared_actions(): void
    {
        foreach ([$this->judge, $this->master] as $user) {
            $this->login($user);
            $identity = $this->getJson('/api/mobile/auth/user')->assertOk();
            $this->assertNotContains('feed', $identity['user']['navigation']['bottom']);
            foreach (['/feed', '/students', '/championships', '/examinations', '/education', '/notifications', '/trainer/profile'] as $path) {
                $this->assertContains($this->getJson('/api/mobile'.$path)->status(), [401, 403]);
            }
            $this->getJson('/api/mobile/agreements')->assertOk();
        }
        $this->getJson('/api/mobile/judge/tables')->assertForbidden();
        $this->getJson('/api/mobile/about')->assertOk();
        $this->login($this->judge);
        $this->getJson('/api/mobile/rating')->assertForbidden();
        $this->getJson('/api/mobile/master/works')->assertForbidden();
        $this->postJson('/api/mobile/account/delete', ['password' => 'password', 'confirmed' => true])->assertForbidden();
    }

    public function test_judge_queue_own_column_and_no_sensitive_payload(): void
    {
        $this->login($this->judge);
        $this->getJson('/api/mobile/judge/tables')->assertOk()->assertJsonCount(1, 'data');
        $url = '/api/mobile/judge/tables/'.$this->list->id;
        $response = $this->getJson($url)->assertOk()->assertJsonPath('data.0.students.0.club', 'Correct club');
        foreach (['referee_score', 'total_score', 'min_score', 'winner_1', 'email', 'passport', '9.9'] as $secret) {
            $this->assertStringNotContainsString($secret, $response->getContent());
        }
        $scoreUrl = $url.'/scores/'.$this->pool->id;
        $this->postJson($scoreUrl, ['field' => 'referee_score', 'value' => '1', 'original_value' => '9.9'])->assertForbidden();
        $this->postJson($scoreUrl, ['field' => 'judge1_score', 'value' => '8,1', 'original_value' => null])->assertOk();
        $this->assertEquals(8.1, $this->pool->fresh()->judge1_score);
        $this->postJson($scoreUrl, ['field' => 'judge1_score', 'value' => '8.2', 'original_value' => null])->assertConflict();
        $this->postJson($scoreUrl, ['field' => 'judge1_score', 'value' => '10.1', 'original_value' => '8.1'])->assertUnprocessable();
        $this->postJson($scoreUrl, ['field' => 'judge1_score', 'value' => null, 'original_value' => '8.1'])->assertOk();
        $this->assertNull($this->pool->fresh()->total_score);
        $this->assertDatabaseHas('activity_log', ['event' => 'tournament.kata.score_updated', 'causer_id' => $this->judge->id]);
    }

    public function test_all_positions_and_position_revocation(): void
    {
        $this->login($this->judge);
        foreach (KataScores::FIELDS as $field) {
            $this->judge->update(['judge_position' => $field]);
            $this->getJson('/api/mobile/judge/tables/'.$this->list->id)->assertOk()->assertJsonPath('field', $field);
        }
        $this->judge->update(['judge_position' => null]);
        $this->getJson('/api/mobile/judge/tables/'.$this->list->id)->assertOk()->assertJsonPath('can_score', false);
        $this->postJson('/api/mobile/judge/tables/'.$this->list->id.'/scores/'.$this->pool->id,
            ['field' => 'judge4_score', 'value' => '8', 'original_value' => null])->assertForbidden();
        $this->judge->update(['organization_id' => $this->master->id]);
        $this->getJson('/api/mobile/judge/tables')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/mobile/judge/tables/'.$this->list->id)->assertNotFound();
    }

    public function test_judge_cannot_invalidate_final_or_get_full_pdf(): void
    {
        $this->login($this->judge);
        KataPool::create(['list_id' => $this->list->id, 'tournament_id' => $this->tournament->id, 'student_id' => $this->student->id, 'round' => 'FINAL']);
        $this->getJson('/api/mobile/judge/tables/'.$this->list->id)->assertOk()->assertJsonPath('can_score', false);
        $this->postJson('/api/mobile/judge/tables/'.$this->list->id.'/scores/'.$this->pool->id,
            ['field' => 'judge1_score', 'value' => '8', 'original_value' => null, 'confirmed' => true])->assertForbidden();
        $this->assertEquals(2, KataPool::count());
        $this->actingAs($this->judge)->getJson('/api/panel/tournaments/'.$this->tournament->championship_id.'/items/'.$this->tournament->id.'/kata/'.$this->list->id.'/pdf')->assertForbidden();
    }

    public function test_judge_video_is_round_specific_private_and_bound_to_pool(): void
    {
        $this->login($this->judge);
        Storage::disk('protected')->put('video/first.mp4', '0123456789');
        StudentTournament::create(['student_id' => $this->student->id, 'tournament_id' => $this->tournament->id,
            'list_tournament_id' => $this->list->id, 'online_kata_first_round_video_path' => 'video/first.mp4']);
        $url = '/api/mobile/files/judge/'.$this->pool->id.'/'.$this->student->id;
        $this->get($url, ['Range' => 'bytes=0-3'])->assertStatus(206);
        $this->get('/api/mobile/files/judge/'.$this->pool->id.'/'.$this->master->id)->assertNotFound();
        $this->pool->update(['round' => 'FINAL']);
        $this->get($url)->assertNotFound();
        $this->tournament->update(['date_finish' => now()->subDay()]);
        $this->getJson('/api/mobile/judge/tables')->assertOk()->assertJsonCount(0, 'data');
        $this->get($url)->assertNotFound();
    }

    public function test_master_only_assigned_paid_works_review_revision_and_media(): void
    {
        $this->login($this->master);
        $work = $this->work();
        $unpaid = $this->work(['is_payment' => false]);
        $foreign = $this->work(['reviewer_id' => $this->judge->id]);
        $this->getJson('/api/mobile/master/works')->assertOk()->assertJsonCount(1, 'data');
        foreach ([$unpaid, $foreign] as $hidden) {
            $this->getJson('/api/mobile/master/works/'.$hidden->id)->assertNotFound();
            $this->get('/api/mobile/files/master/'.$hidden->id)->assertNotFound();
        }
        $url = '/api/mobile/master/works/'.$work->id;
        $detail = $this->getJson($url)->assertOk()['data'];
        $this->assertEquals('Correct club', $detail['club']);
        $data = ['revision' => $detail['revision'], 'description' => 'Review', 'point' => '87.5', 'detail_point' => 'Excellent',
            'recommendation' => 'Practice', 'is_review' => true];
        $this->postJson($url, $data + ['is_payment' => false])->assertUnprocessable();
        $saved = $this->postJson($url, $data)->assertOk()->assertJsonPath('data.is_review', true)['data'];
        $this->postJson($url, $data)->assertOk();
        $this->assertEquals(1, DB::table('activity_log')->where('event', 'education.review.updated')->count());
        $this->postJson($url, array_replace($data, ['description' => 'Stale']))->assertConflict();
        $reopen = array_replace($data, ['revision' => $saved['revision'], 'is_review' => false]);
        $this->postJson($url, $reopen)->assertUnprocessable();
        $this->postJson($url, $reopen + ['confirmed' => true])->assertOk()->assertJsonPath('data.is_review', false);
        Storage::disk('protected')->put('video/work.mp4', '0123456789');
        $this->get('/api/mobile/files/master/'.$work->id, ['Range' => 'bytes=0-3'])->assertStatus(206);
        $work->update(['reviewer_id' => $this->judge->id]);
        $this->get($url)->assertNotFound();
        $this->assertDatabaseHas('activity_log', ['event' => 'education.review.updated', 'causer_id' => $this->master->id]);
    }

    public function test_master_profile_round_trip_files_protected_and_delete_guard(): void
    {
        $this->login($this->master);
        $this->getJson('/api/mobile/staff/profile')->assertOk()->assertJsonPath('data.fields.patronymic', 'Middle');
        $this->postJson('/api/mobile/staff/profile', ['first_name' => 'Updated'])->assertOk()->assertJsonPath('data.fields.patronymic', 'Middle');
        $this->postJson('/api/mobile/staff/profile', ['club' => 'Hack'])->assertUnprocessable();
        $this->postJson('/api/mobile/staff/profile', ['birthday' => 'bad'])->assertUnprocessable();
        $this->post('/api/mobile/staff/profile', ['passport' => UploadedFile::fake()->image('passport.jpg')], ['Accept' => 'application/json'])->assertOk();
        $path = $this->master->fresh()->passport;
        Storage::disk('protected')->assertExists($path);
        $this->get('/api/mobile/files/users/'.$this->master->id.'/passport')->assertOk();
        $this->get('/api/mobile/files/users/'.$this->student->id.'/passport')->assertForbidden();
        $this->postJson('/api/mobile/staff/profile', ['remove_documents' => ['passport']])->assertOk();
        Storage::disk('protected')->assertMissing($path);
        $work = $this->work();
        $this->postJson('/api/mobile/account/delete', ['password' => 'password', 'confirmed' => true])->assertForbidden();
        $work->update(['is_review' => true]);
        $this->postJson('/api/mobile/account/delete', ['password' => 'bad', 'confirmed' => true])->assertUnprocessable();
        $this->postJson('/api/mobile/account/delete', ['password' => 'password', 'confirmed' => true])->assertOk();
        $this->assertSoftDeleted('users', ['id' => $this->master->id]);
        $this->assertDatabaseHas('education_klass_videos', ['id' => $work->id, 'reviewer_id' => $this->master->id]);
        $this->getJson('/api/mobile/auth/user')->assertUnauthorized();
    }

    public function test_staff_login_consent_logout_and_mixed_role_denial(): void
    {
        foreach ([$this->judge, $this->master] as $user) {
            $result = $this->postJson('/api/mobile/auth/login', ['email' => $user->email, 'password' => 'password'])->assertOk();
            $token = $result['token'];
            $this->withToken($token)->getJson('/api/mobile/staff/profile')->assertStatus(428);
            $this->withToken($token)->getJson('/api/mobile/agreements')->assertOk();
            $this->withToken($token)->postJson('/api/mobile/auth/logout')->assertOk();
            $this->withToken($token)->getJson('/api/mobile/auth/user')->assertUnauthorized();
        }
        DB::table('model_has_roles')->insert(['model_type' => User::class, 'model_id' => $this->judge->id,
            'role_id' => DB::table('roles')->where('name', 'Coach')->value('id')]);
        $this->postJson('/api/mobile/auth/login', ['email' => $this->judge->email, 'password' => 'password'])->assertUnprocessable();
    }

    public function test_review_publication_and_reopen_control_existing_student_and_coach_views(): void
    {
        foreach (['Student', 'Coach'] as $role) {
            foreach (['view_education::klass::video', 'view_any_education::klass::video'] as $name) {
                $permission = DB::table('permissions')->where('name', $name)->value('id') ?? DB::table('permissions')->insertGetId(['name' => $name, 'guard_name' => 'web']);
                DB::table('role_has_permissions')->insert(['permission_id' => $permission, 'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
            }
        }
        $work = $this->work(['description' => 'Hidden draft']);
        $url = '/api/mobile/education/works/'.$work->id;
        $this->login($this->student);
        $this->getJson($url)->assertOk()->assertJsonMissingPath('data.review');
        $this->login($this->master);
        $revision = $this->getJson('/api/mobile/master/works/'.$work->id)['data']['revision'];
        $data = ['revision' => $revision, 'description' => 'Published', 'point' => '88', 'detail_point' => '90', 'recommendation' => 'Practice', 'is_review' => true];
        $saved = $this->postJson('/api/mobile/master/works/'.$work->id, $data)->assertOk()['data'];
        foreach ([$this->student, $this->student->coach] as $reader) {
            $this->login($reader);
            $this->getJson($url)->assertOk()->assertJsonPath('data.review.description', 'Published');
        }
        $this->login($this->master);
        $this->postJson('/api/mobile/master/works/'.$work->id, array_replace($data, ['revision' => $saved['revision'], 'is_review' => false, 'confirmed' => true]))->assertOk();
        foreach ([$this->student, $this->student->coach] as $reader) {
            $this->login($reader);
            $this->getJson($url)->assertOk()->assertJsonMissingPath('data.review');
        }
    }

    public function test_staff_lists_paginate_search_and_keep_query_count_bounded(): void
    {
        $this->login($this->master);
        $work = $this->work();
        $queries = 0;
        DB::listen(function () use (&$queries) {
            $queries++;
        });
        $queries = 0;
        $this->getJson('/api/mobile/master/works')->assertOk();
        $baseline = $queries;
        for ($i = 0; $i < 40; $i++) {
            $work->replicate()->save();
        }
        $queries = 0;
        $this->getJson('/api/mobile/master/works')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.total', 41);
        $this->assertLessThanOrEqual($baseline + 2, $queries);
        $this->getJson('/api/mobile/master/works?page=3')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/mobile/master/works?search=absent')->assertOk()->assertJsonCount(0, 'data');
        $this->login($this->judge);
        $queries = 0;
        $this->getJson('/api/mobile/judge/tables/'.$this->list->id)->assertOk();
        $baseline = $queries;
        for ($i = 0; $i < 35; $i++) {
            $this->pool->replicate()->save();
        }
        $queries = 0;
        $this->getJson('/api/mobile/judge/tables/'.$this->list->id)->assertOk()->assertJsonCount(30, 'data')->assertJsonPath('meta.total', 36);
        $this->assertLessThanOrEqual($baseline + 2, $queries);
        $this->getJson('/api/mobile/judge/tables/'.$this->list->id.'?page=2')->assertOk()->assertJsonCount(6, 'data');
        $this->getJson('/api/mobile/judge/tournaments')->assertOk()->assertJsonPath('data.0.id', $this->tournament->id);
        $this->getJson('/api/mobile/judge/tables?tournament_id='.$this->tournament->id.'&search=Kata')->assertOk()->assertJsonCount(1, 'data');
        $this->getJson('/api/mobile/judge/tables?tournament_id=999999')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_master_mutations_roll_back_with_audit_and_file_failure(): void
    {
        $this->login($this->master);
        $work = $this->work();
        $revision = $this->getJson('/api/mobile/master/works/'.$work->id)['data']['revision'];
        $this->master->update(['passport' => 'passport/old.jpg']);
        Storage::disk('protected')->put('passport/old.jpg', 'Original');
        $fail = true;
        DB::listen(function ($query) use (&$fail) {
            if ($fail && str_contains($query->sql, 'insert into') && str_contains($query->sql, 'activity_log')) {
                $fail = false;
                throw new \RuntimeException('Simulated audit failure');
            }
        });
        $this->postJson('/api/mobile/master/works/'.$work->id, ['revision' => $revision, 'description' => 'Lost', 'point' => '8', 'detail_point' => '9', 'recommendation' => '', 'is_review' => true])->assertStatus(500);
        $this->assertFalse($work->fresh()->is_review);
        $this->assertNull($work->fresh()->description);
        $fail = true;
        $this->post('/api/mobile/staff/profile', ['passport' => UploadedFile::fake()->image('new.jpg')], ['Accept' => 'application/json'])->assertStatus(500);
        $this->assertSame('passport/old.jpg', $this->master->fresh()->passport);
        Storage::disk('protected')->assertExists('passport/old.jpg');
        $this->assertEquals(['passport/old.jpg'], Storage::disk('protected')->allFiles());
    }
}
