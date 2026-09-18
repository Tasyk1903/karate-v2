<?php

namespace Tests\Feature;

use App\Models\EducationKlassVideo;
use App\Models\MobileAccessToken;
use App\Models\User;
use App\Services\Education\EducationWorkPayments;
use App\Services\Tournaments\Payments\YooKassaGateway;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MobileEducationTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    private User $student;

    private User $other;

    private int $category;

    private int $role;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        $this->role = DB::table('roles')->insertGetId(['name' => 'Coach', 'guard_name' => 'web']);
        DB::table('old_roles')->insert(['id' => $this->role, 'name' => 'Coach']);
        $studentRole = DB::table('roles')->insertGetId(['name' => 'Student', 'guard_name' => 'web']);
        DB::table('old_roles')->insert(['id' => $studentRole, 'name' => 'Student']);
        $this->coach = User::create(['name' => 'Coach', 'first_name' => 'Coach', 'last_name' => 'One', 'club' => 'Coach club', 'email' => 'coach@example.test', 'password' => 'password', 'role_id' => $this->role]);
        $this->student = User::create(['name' => 'Student', 'first_name' => 'Student', 'last_name' => 'One', 'club' => 'Wrong club', 'rang' => '9 кю', 'email' => 'student@example.test', 'password' => 'password', 'role_id' => $studentRole, 'coach_id' => $this->coach->id]);
        $this->other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'password', 'role_id' => $studentRole]);
        $this->category = DB::table('education_klass_categories')->insertGetId(['name' => 'Taikyoku', 'price' => 10]);
        foreach (['education::kata::category', 'kata::competitions', 'education::klass::video'] as $resource) {
            foreach (['view_', 'view_any_'] as $prefix) {
                $id = DB::table('permissions')->insertGetId(['name' => $prefix.$resource, 'guard_name' => 'web']);
                DB::table('role_has_permissions')->insert(['role_id' => $this->role, 'permission_id' => $id]);
            }
        }
        MobileAccessToken::create(['user_id' => $this->coach->id, 'name' => 'test', 'token' => hash('sha256', 'education-test'), 'expires_at' => now()->addMonth()]);
        $this->acceptMobileAgreements($this->coach);
        $this->withToken('education-test');
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function work(array $data = []): int
    {
        return DB::table('education_klass_videos')->insertGetId(array_merge([
            'student_id' => $this->student->id, 'education_klass_category_id' => $this->category,
            'path' => 'legacy/work.mp4', 'is_payment' => true, 'is_review' => false,
            'description' => 'Private draft', 'point' => '8', 'detail_point' => '9',
            'recommendation' => 'Private recommendation',
        ], $data));
    }

    private function catalog(string $type = 'kata_attestation'): array
    {
        $competition = $type === 'competition';
        $id = DB::table($competition ? 'kata_competitions' : 'education_kata_categories')->insertGetId([
            'name' => $type, ...($competition ? [] : ['type' => $type]),
        ]);
        $video = DB::table($competition ? 'kata_competitions_videos' : 'education_kata_videos')->insertGetId([
            $competition ? 'kata_competition_id' : 'education_kata_category_id' => $id,
            'title' => 'Lesson', 'path' => 'legacy/'.$type.'.mp4', 'poster_path' => 'legacy/'.$type.'.jpg',
        ]);

        return [$id, $video];
    }

    public function test_catalog_sections_categories_and_files_are_read_only_and_partitioned(): void
    {
        $this->getJson('/api/mobile/education')->assertOk()->assertJsonCount(5, 'data');
        Storage::fake('public');
        foreach (['kata_attestation', 'kihon', 'ido_geiko', 'competition'] as $section) {
            [$category, $video] = $this->catalog($section);
            Storage::disk('public')->put('legacy/'.$section.'.mp4', '0123456789');
            Storage::disk('public')->put('legacy/'.$section.'.jpg', 'image');
            $this->getJson('/api/mobile/education/catalog/'.$section)->assertOk()
                ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $category);
            $row = $this->getJson("/api/mobile/education/catalog/{$section}/{$category}")->assertOk()
                ->assertJsonPath('data.0.title', 'Lesson')['data'][0];
            $this->assertArrayNotHasKey('path', $row);
            $this->get($row['video_url'], ['Range' => 'bytes=0-3'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 0-3/10');
            $this->get($row['poster_url'])->assertOk()->assertHeader('Cache-Control', 'no-store, private');
            $this->get('/storage/legacy/'.$section.'.mp4')->assertNotFound();
            $this->get('/storage/legacy/'.$section.'.jpg')->assertNotFound();
            $this->postJson("/api/mobile/education/catalog/{$section}/{$category}", ['name' => 'Overwrite'])->assertStatus(405);
        }
        [$category, $video] = $this->catalog('kihon');
        $this->getJson("/api/mobile/education/catalog/ido_geiko/{$category}")->assertNotFound();
        $this->get("/api/mobile/files/education/catalog/ido_geiko/{$video}/video")->assertNotFound();
        $this->get('/api/mobile/files/education/catalog/kihon/'.$video.'/path')->assertNotFound();
    }

    public function test_each_resource_requires_both_permissions_and_supports_direct_and_pivot_roles(): void
    {
        [$category, $video] = $this->catalog();
        $permission = DB::table('permissions')->where('name', 'view_education::kata::category')->value('id');
        DB::table('role_has_permissions')->where('permission_id', $permission)->delete();
        $this->getJson('/api/mobile/education')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/api/mobile/education/catalog/kihon')->assertForbidden();
        $this->getJson("/api/mobile/education/catalog/kata_attestation/{$category}")->assertForbidden();
        $this->get("/api/mobile/files/education/catalog/kata_attestation/{$video}/video")->assertForbidden();
        DB::table('model_has_permissions')->insert(['model_id' => $this->coach->id, 'model_type' => User::class, 'permission_id' => $permission]);
        $this->getJson('/api/mobile/education/catalog/kata_attestation')->assertOk();
        DB::table('model_has_roles')->insert(['model_id' => $this->coach->id, 'model_type' => User::class, 'role_id' => $this->role]);
        $this->coach->update(['role_id' => null]);
        $this->getJson('/api/mobile/education')->assertOk()->assertJsonCount(5, 'data');
        DB::table('role_has_permissions')->delete();
        DB::table('model_has_permissions')->delete();
        $this->getJson('/api/mobile/education')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/mobile/education/works')->assertForbidden();
        $this->getJson('/api/mobile/education/catalog/competition')->assertForbidden();
    }

    public function test_only_paid_own_student_works_are_visible_and_draft_reviews_never_leave_api(): void
    {
        $own = $this->work();
        $unpaid = $this->work(['is_payment' => false]);
        $foreign = $this->work(['student_id' => $this->other->id]);
        $this->withHeader('Accept-Language', 'en')->getJson('/api/mobile/education/works')->assertOk()->assertJsonPath('data.0.rank', '9 kyu');
        $list = $this->withHeader('Accept-Language', 'ru')->getJson('/api/mobile/education/works')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $own)->assertJsonPath('data.0.club', 'Coach club')
            ->assertJsonPath('data.0.rank', '9 кю')->assertJsonPath('data.0.coach_name', 'One Coach');
        $this->assertStringNotContainsString('Private', $list->getContent());
        $detail = $this->getJson('/api/mobile/education/works/'.$own)->assertOk()->assertJsonPath('data.is_review', false);
        $this->assertArrayNotHasKey('review', $detail['data']);
        foreach ([$foreign, $unpaid] as $id) {
            $this->getJson('/api/mobile/education/works/'.$id)->assertNotFound();
            $this->get('/api/mobile/files/education/works/'.$id)->assertNotFound();
        }
        DB::table('education_klass_videos')->where('id', $own)->update(['is_review' => true]);
        $this->getJson('/api/mobile/education/works/'.$own)->assertOk()->assertJsonPath('data.review.description', 'Private draft')
            ->assertJsonPath('data.review.point', '8')->assertJsonPath('data.review.detail_point', '9')
            ->assertJsonPath('data.review.recommendation', 'Private recommendation');
        $this->patchJson('/api/mobile/education/works/'.$own, ['point' => 10])->assertStatus(405);
        $this->postJson('/api/mobile/education/works')->assertForbidden();
    }

    public function test_paid_video_is_protected_even_legacy_public_path_and_access_is_rechecked(): void
    {
        Storage::fake('public');
        $work = $this->work();
        Storage::disk('public')->put('legacy/work.mp4', '0123456789');
        $this->get('/storage/legacy/work.mp4')->assertNotFound();
        $this->get('/api/mobile/files/education/works/'.$work, ['Range' => 'bytes=2-5'])->assertStatus(206);
        $this->student->update(['coach_id' => null]);
        $this->get('/api/mobile/files/education/works/'.$work)->assertNotFound();
        $this->getJson('/api/mobile/education/works')->assertJsonCount(0, 'data');
    }

    public function test_deleted_students_and_non_students_are_not_included(): void
    {
        $work = $this->work();
        $this->student->delete();
        $this->getJson('/api/mobile/education/works')->assertJsonCount(0, 'data');
        $this->getJson('/api/mobile/education/works/'.$work)->assertNotFound();
        $this->student->restore();
        $this->student->update(['role_id' => $this->role]);
        $this->getJson('/api/mobile/education/works')->assertJsonCount(0, 'data');
    }

    public function test_catalogs_and_works_are_paginated_searchable_and_queries_are_bounded(): void
    {
        for ($i = 0; $i < 43; $i++) {
            $this->work();
            $this->catalog('kihon');
        }
        $a = $this->getJson('/api/mobile/education/works')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.last_page', 3);
        $b = $this->getJson('/api/mobile/education/works?page=2')->assertOk()->assertJsonCount(20, 'data');
        $this->assertEmpty(array_intersect(array_column($a['data'], 'id'), array_column($b['data'], 'id')));
        $this->getJson('/api/mobile/education/works?page=3')->assertJsonCount(3, 'data');
        $this->getJson('/api/mobile/education/works?search=Nobody')->assertJsonCount(0, 'data');
        $this->getJson('/api/mobile/education/works?search=Student')->assertJsonPath('meta.total', 43);
        $this->getJson('/api/mobile/education/catalog/kihon?page=3')->assertJsonCount(3, 'data');
        $this->getJson('/api/mobile/education/catalog/kihon?search=missing')->assertJsonCount(0, 'data');
        $this->getJson('/api/mobile/education/works?page=0')->assertUnprocessable();
        DB::enableQueryLog();
        $this->getJson('/api/mobile/education/works')->assertOk();
        $queries = count(DB::getQueryLog());
        DB::flushQueryLog();
        $this->getJson('/api/mobile/education/works?page=3')->assertOk();
        $this->assertSame($queries, count(DB::getQueryLog()));
        DB::disableQueryLog();
    }

    public function test_student_catalog_requires_consent_and_resource_permissions_but_not_coach_role(): void
    {
        $this->withToken('invalid')->getJson('/api/mobile/education')->assertUnauthorized();
        MobileAccessToken::create(['user_id' => $this->student->id, 'name' => 'test', 'token' => hash('sha256', 'student-token'), 'expires_at' => now()->addMonth()]);
        $this->withToken('student-token')->getJson('/api/mobile/education')->assertStatus(428);
        $this->acceptMobileAgreements($this->student);
        $this->getJson('/api/mobile/education')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/mobile/education/catalog/kihon')->assertForbidden();
        foreach (DB::table('permissions')->pluck('id') as $permission) {
            DB::table('role_has_permissions')->insert(['role_id' => $this->student->role_id, 'permission_id' => $permission]);
        }
        $this->getJson('/api/mobile/education')->assertOk()->assertJsonCount(5, 'data');
        $this->getJson('/api/mobile/education/catalog/kihon')->assertOk();
        $this->getJson('/api/mobile/education/works')->assertOk()->assertJsonCount(0, 'data');
    }

    private function studentSession(): void
    {
        $this->acceptMobileAgreements($this->student);
        $this->acceptPaymentOffer($this->student);
        foreach (DB::table('permissions')->pluck('id') as $permission) {
            DB::table('role_has_permissions')->insertOrIgnore(['role_id' => $this->student->role_id, 'permission_id' => $permission]);
        }
        MobileAccessToken::create(['user_id' => $this->student->id, 'name' => 'test', 'token' => hash('sha256', 'student-works'), 'expires_at' => now()->addMonth()]);
        $this->withToken('student-works');
        Storage::fake('protected');
    }

    public function test_student_works_create_replace_delete_and_protect_review_and_foreign_work(): void
    {
        $this->studentSession();
        $foreign = $this->work(['student_id' => $this->other->id]);
        $data = ['category_id' => $this->category, 'student_id' => $this->other->id, 'is_payment' => true, 'point' => 10,
            'video' => UploadedFile::fake()->create('kata.mp4', 10, 'video/mp4')];
        $response = $this->post('/api/mobile/education/works', $data, ['Accept' => 'application/json'])->assertCreated()
            ->assertJsonPath('data.student_id', $this->student->id)->assertJsonPath('data.is_payment', false)->assertJsonPath('data.price', '10.00')
            ->assertJsonPath('data.club', 'Coach club')->assertJsonMissingPath('data.review');
        $id = $response->json('data.id');
        $work = EducationKlassVideo::findOrFail($id);
        $oldPath = $work->path;
        Storage::disk('protected')->put($oldPath, '0123456789');
        $this->get('/api/mobile/files/education/works/'.$id, ['Range' => 'bytes=0-3'])->assertStatus(206);
        $this->getJson('/api/mobile/education/works/'.$foreign)->assertNotFound();
        $this->postJson('/api/mobile/education/works/'.$foreign, ['category_id' => $this->category])->assertNotFound();
        $this->postJson('/api/mobile/education/works/'.$foreign.'/delete', ['confirmed' => true])->assertNotFound();
        $this->post('/api/mobile/education/works/'.$id, ['category_id' => $this->category,
            'video' => UploadedFile::fake()->create('new.mp4', 10, 'video/mp4')], ['Accept' => 'application/json'])->assertOk();
        Storage::disk('protected')->assertMissing($oldPath);
        $work->refresh()->update(['is_review' => true, 'description' => 'Reviewed']);
        $this->getJson('/api/mobile/education/works/'.$id)->assertOk()->assertJsonPath('data.review.description', 'Reviewed')->assertJsonPath('data.can_edit', false);
        $this->postJson('/api/mobile/education/works/'.$id.'/delete', ['confirmed' => true])->assertStatus(409);
        $this->postJson('/api/mobile/education/works/'.$id, ['category_id' => $this->category])->assertStatus(409);
        $work->update(['is_review' => false]);
        $path = $work->path;
        $this->postJson('/api/mobile/education/works/'.$id.'/delete', ['confirmed' => true])->assertOk();
        Storage::disk('protected')->assertMissing($path);
        $this->assertDatabaseMissing('education_klass_videos', ['id' => $id]);
    }

    public function test_student_education_payment_is_durable_and_snapshot_validated_without_real_charge(): void
    {
        $this->studentSession();
        config(['yookassa.shop_id' => 'education-test', 'yookassa.api_key' => 'test-only']);
        $id = $this->work(['is_payment' => false]);
        Storage::disk('protected')->put('legacy/work.mp4', 'video');
        $provider = [];
        $keys = [];
        $this->mock(YooKassaGateway::class, function ($mock) use (&$provider, &$keys) {
            $mock->shouldReceive('create')->andReturnUsing(function ($payload, $key) use (&$provider, &$keys) {
                $keys[] = $key;

                return $provider = ['id' => 'education-'.$key, 'status' => 'pending', 'paid' => false, 'metadata' => $payload['metadata'],
                    'amount' => $payload['amount'], 'recipient' => ['account_id' => 'education-test'], 'confirmation' => ['confirmation_url' => 'https://example.test/pay']];
            });
            $mock->shouldReceive('fetch')->andReturnUsing(function () use (&$provider) {
                return $provider;
            });
        });
        $base = '/api/mobile/education/works/'.$id;
        $this->postJson($base.'/payment')->assertUnprocessable();
        $this->postJson($base.'/payment', ['accepted' => true])->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->assertCount(0, $keys);
        $this->assertDatabaseCount('education_payments', 0);
        $masterRole = DB::table('roles')->insertGetId(['name' => 'Master', 'guard_name' => 'web']);
        DB::table('old_roles')->insert(['id' => $masterRole, 'name' => 'Master']);
        $master = User::create(['name' => 'Master', 'email' => 'reviewer@example.test', 'password' => 'password', 'role_id' => $masterRole]);
        $this->postJson($base.'/payment', ['accepted' => true, 'amount' => 1])->assertOk()->assertJsonPath('payment.amount', '10.00');
        $this->assertDatabaseHas('education_klass_videos', ['id' => $id, 'reviewer_id' => $master->id]);
        $this->postJson($base.'/payment', ['accepted' => true])->assertOk();
        $this->assertCount(1, $keys);
        $this->postJson($base.'/delete', ['confirmed' => true])->assertStatus(409);
        $provider['status'] = 'succeeded';
        $provider['paid'] = true;
        $payments = app(EducationWorkPayments::class);
        $this->assertTrue($payments->confirm($provider['id']));
        $this->assertTrue($payments->confirm($provider['id']));
        $this->getJson($base.'/payment')->assertOk()->assertJsonPath('payment.status', 'fulfilled');
        $this->assertDatabaseHas('education_klass_videos', ['id' => $id, 'is_payment' => true]);
        $this->assertSame(1, DB::table('activity_log')->where('event', 'education.work.paid')->count());
        $this->postJson($base.'/payment', ['accepted' => true])->assertStatus(409);

        $second = $this->work(['is_payment' => false, 'reviewer_id' => $master->id]);
        $this->postJson('/api/mobile/education/works/'.$second.'/payment', ['accepted' => true])->assertOk();
        $master->delete();
        $provider['status'] = 'succeeded';
        $provider['paid'] = true;
        $payments->confirm($provider['id']);
        $this->getJson('/api/mobile/education/works/'.$second.'/payment')->assertOk()
            ->assertJsonPath('payment.status', 'conflict')->assertJsonPath('payment.error_code', 'reviewer_unavailable');
        $this->assertDatabaseHas('education_klass_videos', ['id' => $second, 'is_payment' => false]);
    }
}
