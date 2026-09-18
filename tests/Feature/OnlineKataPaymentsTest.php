<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\KataPool;
use App\Models\ListTournament;
use App\Models\MobileAccessToken;
use App\Models\OnlineKataApplication;
use App\Models\StudentTournament;
use App\Models\TemplateStudentList;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Tournaments\OnlineKataPaymentService;
use App\Services\Tournaments\Payments\KataPaymentReconciler;
use App\Services\Tournaments\Payments\YooKassaGateway;
use App\Services\Tournaments\StudentTournamentListAssignmentService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class OnlineKataPaymentsTest extends TestCase
{
    use RefreshDatabase;

    private User $org;

    private User $coach;

    private User $student;

    private Tournament $tournament;

    private int $category;

    private array $provider = [];

    private array $requests = [];

    private bool $unavailable = false;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        $this->travelTo(now()->setDate(2026, 9, 5)->setTime(12, 0));
        Storage::fake('protected');
        Storage::fake('public');
        config(['yookassa.shop_id' => 'test-shop', 'yookassa.api_key' => 'fake-secret', 'yookassa.online_kata_price' => 1000]);
        foreach (['Organization', 'Coach', 'Student'] as $name) {
            $id = DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $name]);
        }
        $this->org = $this->user('Organization', ['can_edit_coaches' => true]);
        $this->coach = $this->user('Coach', ['organization_id' => $this->org->id, 'club' => 'DOJO']);
        $this->student = $this->user('Student', ['coach_id' => $this->coach->id, 'birthday' => '2016-01-01', 'weight' => 30, 'rang' => '5 кю', 'gender' => 'm']);
        $champ = Championship::create(['name' => 'Champ', 'banner' => 'banner.jpg', 'organization_id' => $this->org->id]);
        $this->tournament = Tournament::create(['name' => 'Online', 'organization_id' => $this->org->id, 'championship_id' => $champ->id,
            'tournament_type' => Tournament::KATA, 'tournament_type_kata' => Tournament::POINT_SYSTEM, 'is_online_kata' => true,
            'age_from' => 0, 'age_to' => 100, 'tatami' => 1, 'price' => 0, 'address' => 'City',
            'date_commission' => now()->addDay(), 'date' => now()->addDays(2), 'date_finish' => now()->addDays(3)]);
        DB::table('tournament_treners')->insert(['tournament_id' => $this->tournament->id, 'trener_id' => $this->coach->id]);
        $this->category = DB::table('education_klass_categories')->insertGetId(['name' => 'Full kata category name', 'price' => 0]);
        MobileAccessToken::create(['user_id' => $this->coach->id, 'name' => 'test', 'token' => hash('sha256', 'kata-test'), 'expires_at' => now()->addMonth()]);
        $this->acceptMobileAgreements($this->coach);
        $this->withToken('kata-test');
        $this->mock(YooKassaGateway::class, function ($mock) {
            $mock->shouldReceive('create')->andReturnUsing(function ($payload, $key) {
                $this->requests[] = [$payload, $key];
                $this->provider = ['id' => 'payment-'.$key, 'status' => 'pending', 'paid' => false,
                    'amount' => $payload['amount'], 'recipient' => ['account_id' => 'test-shop'],
                    'metadata' => $payload['metadata'], 'confirmation' => ['confirmation_url' => 'https://example.test/pay']];
                if ($this->unavailable) {
                    throw new \RuntimeException('Provider timeout');
                }

                return $this->provider;
            });
            $mock->shouldReceive('fetch')->andReturnUsing(fn ($id) => $this->provider);
        });
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function user(string $role, array $fields = []): User
    {
        return User::create($fields + ['first_name' => 'Alex', 'last_name' => 'Test', 'email' => Str::uuid().'@example.test',
            'password' => 'password', 'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
    }

    private function base(): string
    {
        return '/api/mobile/championships/'.$this->tournament->championship_id.'/tournaments/'.$this->tournament->id;
    }

    public function test_first_round_video_limit_is_100_mib_and_rejection_does_not_start_payment(): void
    {
        foreach (['ru', 'en'] as $locale) {
            $response = $this->post($this->base().'/students/online-kata', [
                'student_id' => $this->student->id,
                'online_kata_first_round_category_id' => $this->category,
                'video' => UploadedFile::fake()->create('large.mp4', 102401, 'video/mp4'),
            ], ['Accept' => 'application/json', 'Accept-Language' => $locale]);
            $response->assertUnprocessable()->assertJsonValidationErrors('video');
            $this->assertStringContainsString($locale === 'ru' ? '100 МБ' : '100 MB', $response->json('errors.video.0'));
        }
        $this->assertDatabaseCount('online_kata_applications', 0);
        $this->assertEmpty($this->requests);
        $this->assertEmpty(Storage::disk('protected')->allFiles());
        $this->post($this->base().'/students/online-kata', [
            'student_id' => $this->student->id,
            'online_kata_first_round_category_id' => $this->category,
            'video' => UploadedFile::fake()->create('limit.mp4', 102400, 'video/mp4'),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertCount(1, $this->requests);
    }

    private function create(): OnlineKataApplication
    {
        $this->post($this->base().'/students/online-kata', ['student_id' => $this->student->id,
            'online_kata_first_round_category_id' => $this->category,
            'video' => UploadedFile::fake()->create('round.mp4', 10, 'video/mp4')], ['Accept' => 'application/json'])->assertOk();

        return OnlineKataApplication::latest()->firstOrFail();
    }

    private function pay(OnlineKataApplication $a): OnlineKataApplication
    {
        $this->provider['status'] = 'succeeded';
        $this->provider['paid'] = true;
        app(OnlineKataPaymentService::class)->confirmPayment($this->provider['id']);

        return $a->fresh();
    }

    private function studentSession(): void
    {
        $this->acceptMobileAgreements($this->student);
        $this->acceptPaymentOffer($this->student);
        MobileAccessToken::create(['user_id' => $this->student->id, 'name' => 'test', 'token' => hash('sha256', 'self-payment'), 'expires_at' => now()->addMonth()]);
        $this->withToken('self-payment');
    }

    public function test_student_and_coach_cannot_start_two_payments_for_one_entry(): void
    {
        $this->create();
        $this->studentSession();
        $this->post($this->base().'/students/online-kata', ['student_id' => $this->student->id,
            'online_kata_first_round_category_id' => $this->category,
            'video' => UploadedFile::fake()->create('second.mp4', 10, 'video/mp4')], ['Accept' => 'application/json'])
            ->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->assertDatabaseCount('online_kata_applications', 1);
        $this->assertCount(1, $this->requests);
        $this->assertCount(1, Storage::disk('protected')->allFiles());
    }

    public function test_student_payer_cannot_bypass_payment_or_pay_for_another_student(): void
    {
        $this->studentSession();
        $this->coach->update(['can_attach_to_tournaments_for_students' => false]);
        $this->postJson($this->base().'/self')->assertUnprocessable();
        $this->postJson($this->base().'/students', ['student_ids' => [$this->student->id]])->assertUnauthorized();
        $other = $this->user('Student', ['coach_id' => $this->coach->id]);
        $this->post($this->base().'/students/online-kata', ['student_id' => $other->id,
            'online_kata_first_round_category_id' => $this->category,
            'video' => UploadedFile::fake()->create('round.mp4', 10, 'video/mp4')], ['Accept' => 'application/json'])->assertUnprocessable();
        $a = $this->create();
        $this->assertSame($this->student->id, (int) $a->payer_id);
        $this->travel(6)->seconds();
        $this->assertSame($a->id, $this->create()->id);
        $this->assertSame('fulfilled', $this->pay($a)->status);
        $this->getJson('/api/mobile/online-kata/applications')->assertOk()->assertJsonCount(1, 'data');
        $this->assertDatabaseCount('student_tournaments', 1);
        $this->pay($a);
        $this->assertDatabaseCount('student_tournaments', 1);
    }

    public function test_late_student_payment_after_coach_removal_is_conflict(): void
    {
        $this->studentSession();
        $a = $this->create();
        $this->student->update(['coach_id' => null]);
        $this->assertSame('conflict', $this->pay($a)->status);
        $this->assertDatabaseCount('student_tournaments', 0);
    }

    public function test_retry_reuses_exact_snapshot_key_and_retains_only_first_private_video(): void
    {
        $a = $this->create();
        $this->travel(6)->seconds();
        $b = $this->create();
        $this->assertSame($a->id, $b->id);
        $this->assertCount(1, $this->requests);
        $this->assertCount(1, Storage::disk('protected')->allFiles());
        $this->assertEmpty(Storage::disk('public')->allFiles());
        $this->assertDatabaseCount('student_tournaments', 0);
        $this->assertStringContainsString('/online-kata/payment/complete?application='.$a->id, $a->payload['confirmation']['return_url']);
        $this->assertArrayNotHasKey('video_path', $a->payload['metadata']);
        $this->assertSame('pending', $a->status);
    }

    public function test_uncertain_creation_retries_same_key_and_stops_before_provider_idempotency_expires(): void
    {
        $this->unavailable = true;
        $a = $this->create();
        $this->assertSame('creating', $a->status);
        $this->travel(6)->seconds();
        app(OnlineKataPaymentService::class)->sync($a->fresh());
        $this->assertSame($this->requests[0], $this->requests[1]);
        $this->travel(23)->hours();
        $a = app(OnlineKataPaymentService::class)->sync($a->fresh());
        $this->assertSame('conflict', $a->status);
        $this->assertSame('creation_unknown', $a->error_code);
        $this->assertCount(2, $this->requests);
    }

    public function test_paid_webhook_is_idempotent_and_never_reattaches_after_detach(): void
    {
        $a = $this->pay($this->create());
        $this->assertSame('fulfilled', $a->status);
        $this->assertDatabaseCount('student_tournaments', 1);
        $this->pay($a);
        $this->assertDatabaseCount('tournament_student_lists', 1);
        $entry = StudentTournament::firstOrFail();
        $this->deleteJson($this->base().'/students/'.$entry->id)->assertOk();
        $this->getJson('/api/mobile/online-kata/applications')->assertJsonPath('data.0.status', 'detached');
        $this->pay($a);
        $this->assertDatabaseCount('tournament_student_lists', 0);
        $this->getJson('/api/mobile/online-kata/applications/'.$a->id)->assertOk()->assertJsonPath('payment.status', 'detached');
        $this->assertNotNull($a->fresh()->fulfilled_at);
    }

    public function test_late_payment_after_permission_change_is_logged_conflict_not_success(): void
    {
        $a = $this->create();
        $this->org->update(['can_edit_coaches' => false]);
        $a = $this->pay($a);
        $this->assertSame('conflict', $a->status);
        $this->assertDatabaseCount('student_tournaments', 0);
        $this->assertDatabaseHas('activity_log', ['event' => 'online_kata.payment.conflict', 'causer_id' => $this->coach->id]);
        Storage::disk('protected')->assertExists($a->video_path);
    }

    public function test_late_payment_after_generation_or_student_transfer_is_not_enrolled(): void
    {
        $a = $this->create();
        $template = TemplateStudentList::create(['name' => 'List', 'list_type' => 'kata', 'kata_type' => 'personal', 'user_id' => $this->org->id, 'age_from' => 0, 'age_to' => 100]);
        $list = ListTournament::create(['tournament_id' => $this->tournament->id, 'template_student_list_id' => $template->id]);
        DB::table('kata_pools')->insert(['tournament_id' => $this->tournament->id, 'list_id' => $list->id, 'round' => 'PRELIMINARY STAGE']);
        $this->student->update(['coach_id' => null]);
        $this->assertSame('conflict', $this->pay($a)->status);
        $this->assertDatabaseCount('student_tournaments', 0);
    }

    public function test_verifies_money_currency_recipient_and_application_metadata(): void
    {
        $a = $this->create();
        $verified = $this->provider;
        foreach (['amount.value' => '1.00', 'amount.currency' => 'USD', 'recipient.account_id' => 'other-shop', 'metadata.applicationId' => (string) Str::uuid()] as $field => $wrong) {
            $payment = $verified;
            data_set($payment, $field, $wrong);
            $result = app(KataPaymentReconciler::class)->apply($a, $payment + ['paid' => true]);
            $this->assertSame('payment_mismatch', $result->error_code);
            $this->assertDatabaseCount('student_tournaments', 0);
            $a->update(['status' => 'pending', 'error_code' => null]);
        }
    }

    public function test_canceled_payment_requires_explicit_owner_confirmation_before_new_attempt(): void
    {
        $a = $this->create();
        $this->provider['status'] = 'canceled';
        app(OnlineKataPaymentService::class)->confirmPayment($this->provider['id']);
        $this->assertSame($a->id, $this->create()->id);
        $this->postJson('/api/mobile/online-kata/applications/'.$a->id.'/retry')->assertUnprocessable();
        $this->postJson('/api/mobile/online-kata/applications/'.$a->id.'/retry', ['confirmed' => true])->assertOk();
        $this->travel(1)->seconds();
        $this->assertNotSame($a->id, $this->create()->id);
        $this->assertDatabaseCount('online_kata_applications', 2);
    }

    public function test_application_is_private_and_callback_body_cannot_forge_success(): void
    {
        $a = $this->create();
        $this->postJson('/api/payment-callback', ['object' => ['id' => $this->provider['id'], 'status' => 'succeeded', 'paid' => true]])->assertOk();
        $this->assertSame('pending', $a->fresh()->status);
        $a->update(['payer_id' => $this->org->id]);
        $this->getJson('/api/mobile/online-kata/applications/'.$a->id)->assertForbidden();
        $this->postJson('/api/mobile/online-kata/applications/'.$a->id.'/retry', ['confirmed' => true])->assertForbidden();
        $this->getJson('/api/mobile/online-kata/applications')->assertJsonCount(0, 'data');
    }

    public function test_private_video_range_and_first_round_status_do_not_require_final(): void
    {
        $a = $this->pay($this->create());
        $entry = StudentTournament::firstOrFail();
        Storage::disk('protected')->put($a->video_path, '0123456789');
        $pool = KataPool::create(['student_id' => $this->student->id, 'tournament_id' => $this->tournament->id,
            'list_id' => $entry->list_tournament_id, 'round' => 'PRELIMINARY STAGE']);
        $this->getJson($this->base().'/students')->assertJsonPath('data.0.video_ok', true)->assertJsonPath('data.0.final_video_required', false);
        $url = '/api/mobile/files/kata/'.$pool->id.'/'.$this->student->id;
        $this->get($url, ['Range' => 'bytes=2-5'])->assertStatus(206)->assertHeader('Content-Range', 'bytes 2-5/10');
        $this->student->update(['coach_id' => $this->user('Coach')->id]);
        $this->get($url)->assertForbidden();
    }

    public function test_final_upload_capability_and_post_share_assignment_and_deadline_checks(): void
    {
        $a = $this->pay($this->create());
        $entry = StudentTournament::firstOrFail();
        $pool = KataPool::create(['student_id' => $this->student->id, 'tournament_id' => $this->tournament->id,
            'list_id' => $entry->list_tournament_id, 'round' => 'FINAL']);
        $this->getJson($this->base().'/students')->assertJsonPath('data.0.final_video_required', true);
        $url = $this->base().'/kata-pools/'.$pool->id.'/final-video';
        $this->post($url, ['category_id' => $this->category, 'video' => UploadedFile::fake()->create('final.mp4', 10, 'video/mp4')],
            ['Accept' => 'application/json'])->assertOk();
        $old = $entry->fresh()->online_kata_second_round_video_path;
        DB::table('tournament_treners')->delete();
        $this->post($url, ['category_id' => $this->category, 'video' => UploadedFile::fake()->create('final.mp4', 10, 'video/mp4')],
            ['Accept' => 'application/json'])->assertForbidden();
        $this->assertSame($old, $entry->fresh()->online_kata_second_round_video_path);
        Storage::disk('protected')->assertExists($old);
    }

    public function test_cleanup_removes_canceled_upload_not_pending_or_conflict_evidence(): void
    {
        $a = $this->create();
        $path = $a->video_path;
        $a->update(['status' => 'canceled']);
        $this->travel(8)->days();
        $this->artisan('kata:maintain-applications')->assertSuccessful();
        Storage::disk('protected')->assertMissing($path);
        $this->assertNull($a->fresh()->video_path);
    }

    public function test_public_return_page_does_not_require_browser_session(): void
    {
        $this->get('/online-kata/payment/complete?application='.Str::uuid())->assertOk();
    }

    public function test_orphan_sweep_requires_exclusive_storage_opt_in(): void
    {
        $path = 'online-kata-videos/other-environment.mp4';
        Storage::disk('protected')->put($path, 'video');
        touch(Storage::disk('protected')->path($path), now()->subDays(8)->timestamp);

        $this->assertFalse(config('filesystems.sweep_orphaned_kata_uploads'));
        $this->artisan('kata:maintain-applications')->assertSuccessful();
        Storage::disk('protected')->assertExists($path);

        config(['filesystems.sweep_orphaned_kata_uploads' => true]);
        $this->artisan('kata:maintain-applications')->assertSuccessful();
        Storage::disk('protected')->assertMissing($path);
    }

    public function test_database_failure_does_not_partially_fulfill_and_retry_uses_same_payment(): void
    {
        $a = $this->create();
        $this->mock(StudentTournamentListAssignmentService::class, fn ($m) => $m->shouldReceive('assignToBestList')->andThrow(new \RuntimeException('Database unavailable')));
        $this->provider['status'] = 'succeeded';
        $this->provider['paid'] = true;
        $this->travel(6)->seconds();
        $a = app(OnlineKataPaymentService::class)->sync($a);
        $this->assertSame('enrollment_processing', $a->error_code);
        $this->assertNull($a->fulfilled_at);
        $this->assertDatabaseCount('student_tournaments', 0);
        $this->app->forgetInstance(StudentTournamentListAssignmentService::class);
        $this->travel(6)->seconds();
        $this->assertSame('fulfilled', app(OnlineKataPaymentService::class)->sync($a)->status);
        $this->assertCount(1, $this->requests);
    }

    public function test_kata_payload_has_owned_video_capability_and_full_category_only_for_current_round(): void
    {
        $a = $this->pay($this->create());
        $entry = StudentTournament::firstOrFail();
        $pool = KataPool::create(['student_id' => $this->student->id, 'tournament_id' => $this->tournament->id,
            'list_id' => $entry->list_tournament_id, 'round' => 'FINAL']);
        $path = $this->base().'/lists/'.$entry->list_tournament_id.'/bracket';
        $this->getJson($path)->assertOk()->assertJsonPath('rounds.1.rows.0.can_update_final_video', true);
        $this->student->update(['coach_id' => $this->user('Coach')->id]);
        $this->getJson($path)->assertOk()->assertJsonPath('rounds.1.rows.0.can_update_final_video', false)
            ->assertJsonPath('rounds.1.rows.0.video_url', null)->assertJsonPath('rounds.1.rows.0.video_update_reason', 'not_owner');
    }

    public function test_deleted_championship_and_passed_registration_deadline_produce_conflict(): void
    {
        $a = $this->create();
        $this->tournament->championship->delete();
        $this->travel(4)->days();
        $this->assertSame('conflict', $this->pay($a)->status);
        $this->assertDatabaseCount('student_tournaments', 0);
    }
}
