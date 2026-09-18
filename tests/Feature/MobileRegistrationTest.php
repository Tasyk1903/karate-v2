<?php

namespace Tests\Feature;

use App\Mail\StudentInvitation;
use App\Mail\TrainerEmailVerification;
use App\Mail\TrainerInvitation;
use App\Models\MobileAccessToken;
use App\Models\User;
use App\Services\Team\CoachInvitations;
use App\Services\Team\OrganizationInvitations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class MobileRegistrationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Mail::fake();
        foreach (['Organization', 'Secretary', 'Coach', 'Student'] as $name) {
            $id = DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $name]);
        }
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function user(string $role, array $data = []): User
    {
        return User::create(array_merge(['name' => $role, 'first_name' => 'First', 'last_name' => 'Last',
            'email' => Str::uuid().'@example.test', 'password' => 'safe-password',
            'role_id' => DB::table('roles')->where('name', $role)->value('id')], $data));
    }

    private function data(string $kind, string $email = 'new@example.test', bool $sendInvitation = true): array
    {
        if ($kind === 'trainer') {
            $organization = $this->user('Organization');
            $secretary = $this->user('Secretary', ['organization_id' => $organization->id]);
            if ($sendInvitation) {
                app(OrganizationInvitations::class)->send($secretary, [$email], 'en');
            }
            $code = ['organization_code' => app(OrganizationInvitations::class)->code($secretary)];
        } else {
            $code = ['coach_code' => app(CoachInvitations::class)->code($this->user('Coach'))];
        }

        return $code + ['email' => $email, 'existing_account' => false, 'first_name' => 'New', 'last_name' => 'Member',
            'password' => 'safe-password', 'password_confirmation' => 'safe-password'];
    }

    private function start(string $kind, array $data): array
    {
        $token = $this->withHeader('Accept-Language', 'en')->postJson('/api/mobile/auth/registration/'.$kind, $data)
            ->assertOk()->assertJsonPath('verification_required', true)->json('challenge_token');

        return ['challenge_token' => $token, 'code' => Mail::queued(TrainerEmailVerification::class)->last()->code];
    }

    public function test_native_trainer_registration_verifies_email_without_cookie_or_login_and_consumes_once(): void
    {
        $data = $this->data('trainer');
        $confirmation = $this->start('trainer', $data);
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
        $stored = Cache::get('mobile-registration:'.hash('sha256', $confirmation['challenge_token']));
        $this->assertStringNotContainsString($data['email'], json_encode($stored));
        $this->assertStringNotContainsString($data['password'], json_encode($stored));
        $url = '/api/mobile/auth/registration/trainer/confirm';
        $this->postJson($url, $confirmation)->assertOk()->assertJsonPath('registered', true);
        $user = User::where('email', $data['email'])->firstOrFail();
        $organization = User::where('name', 'Organization')->firstOrFail();
        $this->assertEquals($organization->id, $user->organization_id);
        $this->assertTrue($user->hasProjectRole('Coach'));
        $this->assertNotNull($user->email_verified_at);
        $this->assertGuest();
        $this->assertDatabaseHas('activity_log', ['event' => 'trainer.invitation.accepted', 'causer_id' => $user->id]);
        $this->assertDatabaseMissing('activity_log', ['event' => 'user.logged_in', 'causer_id' => $user->id]);
        $this->postJson($url, $confirmation)->assertUnprocessable();
        $this->assertNull(Cache::get('mobile-registration:'.hash('sha256', $confirmation['challenge_token'])));
    }

    public function test_native_student_registration_assigns_coach_not_organization(): void
    {
        $data = $this->data('student');
        $confirmation = $this->start('student', $data);
        $this->postJson('/api/mobile/auth/registration/student/confirm', $confirmation)->assertOk();
        $user = User::where('email', $data['email'])->firstOrFail();
        $this->assertTrue($user->hasProjectRole('Student'));
        $this->assertEquals(User::where('name', 'Coach')->value('id'), $user->coach_id);
        $this->assertDatabaseHas('wait_confirmation_invitations', ['email' => $data['email'], 'confirmed' => true, 'accepted_user_id' => $user->id]);
    }

    public function test_student_registration_logs_in_and_requires_complete_profile_before_other_actions(): void
    {
        foreach ([2 => 'privacy_policy', 3 => 'data_processing_consent'] as $id => $type) {
            DB::table('agreements')->insert(['id' => $id, 'type' => $type, 'description' => 'Test document']);
        }
        $data = $this->data('student');
        $organization = $this->user('Organization', ['can_edit_students' => false, 'can_edit_coaches' => false]);
        User::where('name', 'Coach')->firstOrFail()->update(['organization_id' => $organization->id]);
        $confirmation = $this->start('student', $data);
        $response = $this->postJson('/api/mobile/auth/registration/student/confirm', $confirmation)
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('user.profile_setup_required', true);
        $user = User::where('email', $data['email'])->firstOrFail();
        $token = $response->json('token');
        $this->assertSame(80, strlen($token));
        $this->assertDatabaseHas('mobile_access_tokens', ['user_id' => $user->id, 'token' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('mobile_access_tokens', ['token' => $token]);
        $this->assertDatabaseHas('activity_log', ['log_name' => 'mobile', 'event' => 'mobile.auth.login', 'causer_id' => $user->id]);
        $this->withToken($token)->getJson('/api/mobile/auth/user')->assertOk()->assertJsonPath('user.profile_setup_required', true);
        $documents = $this->getJson('/api/mobile/agreements')->assertOk()->json('data');
        $this->getJson('/api/mobile/students/'.$user->id)->assertStatus(428);
        foreach ($documents as $document) {
            $this->postJson('/api/mobile/agreements/'.$document['id'].'/accept', ['version' => $document['version'], 'accepted' => true])->assertOk();
        }
        $this->getJson('/api/mobile/students/'.$user->id)->assertOk()
            ->assertJsonPath('student.capabilities.birthday', true)->assertJsonPath('student.capabilities.rang', true);
        $this->getJson('/api/mobile/feed')->assertStatus(409)->assertJsonPath('code', 'profile_setup_required');
        $this->postJson('/api/mobile/feed', ['content' => 'Must not be posted'])->assertStatus(409);
        $this->assertDatabaseCount('posts', 0);
        $this->postJson('/api/mobile/students/'.$user->id, ['student_profile_setup_required' => false])
            ->assertUnprocessable()->assertJsonValidationErrors(['birthday', 'gender', 'weight', 'rang', 'city_training']);
        $profile = ['first_name' => 'New', 'last_name' => 'Student', 'birthday' => '2015-01-03',
            'gender' => 'm', 'weight' => 35, 'rang' => '9 кю', 'city_training' => 'Warsaw'];
        foreach (['birthday' => '2099-01-01', 'weight' => 0, 'rang' => '99 кю', 'city_training' => ''] as $field => $value) {
            $this->postJson('/api/mobile/students/'.$user->id, array_replace($profile, [$field => $value]))
                ->assertUnprocessable()->assertJsonValidationErrors($field);
            $this->assertTrue((bool) $user->refresh()->student_profile_setup_required);
        }
        $this->postJson('/api/mobile/students/'.$user->id, $profile)->assertOk();
        $this->getJson('/api/mobile/auth/user')->assertJsonPath('user.profile_setup_required', false);
        $this->assertDatabaseHas('activity_log', ['event' => 'student.profile_setup.completed', 'causer_id' => $user->id]);
        $this->assertNull($user->refresh()->patronymic);
        $this->postJson('/api/mobile/students/'.$user->id, ['rang' => '1 дан'])->assertOk();
        $this->getJson('/api/mobile/feed')->assertOk();
        $this->postJson('/api/mobile/auth/registration/student/confirm', $confirmation)->assertUnprocessable();
        $this->assertDatabaseCount('mobile_access_tokens', 1);
    }

    public function test_profile_setup_survives_logout_and_login_but_does_not_gate_existing_students(): void
    {
        $data = $this->data('student');
        $confirmation = $this->start('student', $data);
        $token = $this->postJson('/api/mobile/auth/registration/student/confirm', $confirmation)->assertOk()->json('token');
        $this->withToken($token)->postJson('/api/mobile/auth/logout')->assertOk();
        $this->assertDatabaseCount('mobile_access_tokens', 0);
        $this->postJson('/api/mobile/auth/login', ['email' => $data['email'], 'password' => $data['password']])
            ->assertOk()->assertJsonPath('user.profile_setup_required', true);
        $existing = $this->user('Student');
        $this->postJson('/api/mobile/auth/login', ['email' => $existing->email, 'password' => 'safe-password'])
            ->assertOk()->assertJsonPath('user.profile_setup_required', false);
    }

    public function test_session_failure_rolls_back_student_registration_and_keeps_challenge_retryable(): void
    {
        $data = $this->data('student');
        $confirmation = $this->start('student', $data);
        MobileAccessToken::creating(fn () => throw new \RuntimeException('Synthetic token failure'));
        try {
            $this->postJson('/api/mobile/auth/registration/student/confirm', $confirmation)->assertStatus(500);
            $this->assertDatabaseMissing('users', ['email' => $data['email']]);
            $this->assertDatabaseHas('wait_confirmation_invitations', ['email' => $data['email'], 'confirmed' => false]);
            $this->assertDatabaseCount('mobile_access_tokens', 0);
            $this->assertDatabaseMissing('activity_log', ['event' => 'user.registered']);
        } finally {
            MobileAccessToken::flushEventListeners();
        }
        $this->postJson('/api/mobile/auth/registration/student/confirm', $confirmation)->assertOk();
    }

    public function test_shared_codes_register_coach_and_student_without_invitation_email(): void
    {
        foreach (['trainer', 'student'] as $kind) {
            $this->travel(61)->seconds();
            $email = $kind.'@example.test';
            $data = $this->data($kind, $email, sendInvitation: false);
            $key = $kind === 'trainer' ? 'organization_code' : 'coach_code';
            $data[$key] = ' '.strtolower($data[$key]).' ';
            $data['email'] = strtoupper($email);
            $this->assertDatabaseMissing('wait_confirmation_invitations', ['email' => $email]);
            $this->start($kind, $data);
            $confirmation = $this->start($kind, $data);
            $this->assertSame(1, DB::table('wait_confirmation_invitations')->where('email', $email)->count());
            $this->assertDatabaseMissing('users', ['email' => $email]);
            $this->postJson('/api/mobile/auth/registration/'.$kind.'/confirm', $confirmation)->assertOk();
            $user = User::where('email', $email)->firstOrFail();
            $this->assertTrue($user->hasProjectRole($kind === 'trainer' ? 'Coach' : 'Student'));
            $this->assertNotNull($user->email_verified_at);
            $this->assertDatabaseHas('wait_confirmation_invitations', ['email' => $email, 'confirmed' => true, 'accepted_user_id' => $user->id]);
            if ($kind === 'trainer') {
                $this->assertEquals(User::where('name', 'Organization')->value('id'), $user->organization_id);
            } else {
                $this->assertEquals(User::where('name', 'Coach')->value('id'), $user->coach_id);
            }
            $this->postJson('/api/mobile/auth/registration/'.$kind.'/confirm', $confirmation)->assertUnprocessable();
        }
        Mail::assertNotQueued(TrainerInvitation::class);
        Mail::assertNotQueued(StudentInvitation::class);
    }

    public function test_invalid_or_inactive_organization_code_does_not_start_registration(): void
    {
        $data = $this->data('trainer', sendInvitation: false);
        $this->postJson('/api/mobile/auth/registration/trainer', array_replace($data, ['organization_code' => 'KR-INVALID']))
            ->assertUnprocessable()->assertJsonValidationErrors('organization_code');
        $organization = User::where('name', 'Organization')->firstOrFail();
        $organization->delete();
        $this->postJson('/api/mobile/auth/registration/trainer', $data)->assertUnprocessable();
        $organization->restore();
        $organization->forceFill(['role_id' => DB::table('roles')->where('name', 'Student')->value('id')])->save();
        $this->postJson('/api/mobile/auth/registration/trainer', $data)->assertUnprocessable();
        $this->assertDatabaseMissing('wait_confirmation_invitations', ['email' => $data['email']]);
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
        Mail::assertNotQueued(TrainerEmailVerification::class);
    }

    public function test_organization_code_is_rechecked_before_confirming_direct_registration(): void
    {
        $data = $this->data('trainer', sendInvitation: false);
        $confirmation = $this->start('trainer', $data);
        DB::table('organization_join_codes')->where('code', $data['organization_code'])->update(['code' => 'KR-REPLACED']);
        $this->postJson('/api/mobile/auth/registration/trainer/confirm', $confirmation)->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
        $this->assertDatabaseHas('wait_confirmation_invitations', ['email' => $data['email'], 'confirmed' => false]);
    }

    public function test_existing_coach_can_join_by_code_but_cannot_be_taken_from_another_organization(): void
    {
        $user = $this->user('Coach');
        $data = array_replace($this->data('trainer', $user->email, sendInvitation: false), ['existing_account' => true, 'password' => 'wrong']);
        unset($data['password_confirmation']);
        $this->postJson('/api/mobile/auth/registration/trainer', $data)->assertUnprocessable();
        $this->assertDatabaseMissing('wait_confirmation_invitations', ['email' => $user->email]);
        $data['password'] = 'safe-password';
        $confirmation = $this->start('trainer', $data);
        $this->postJson('/api/mobile/auth/registration/trainer/confirm', $confirmation)->assertOk();
        $organizationId = $user->refresh()->organization_id;
        $this->assertNotNull($organizationId);
        $this->assertSame('First', $user->first_name);
        $this->assertSame(1, User::where('email', $user->email)->count());
        $data['organization_code'] = app(OrganizationInvitations::class)->code($this->user('Organization'));
        $this->postJson('/api/mobile/auth/registration/trainer', $data)->assertUnprocessable();
        $this->assertEquals($organizationId, $user->refresh()->organization_id);
    }

    public function test_direct_registration_rechecks_existing_account_before_linking(): void
    {
        $user = $this->user('Coach');
        $data = array_replace($this->data('trainer', $user->email, sendInvitation: false), ['existing_account' => true]);
        $confirmation = $this->start('trainer', $data);
        $otherOrganization = $this->user('Organization');
        $user->forceFill(['organization_id' => $otherOrganization->id])->save();
        $this->postJson('/api/mobile/auth/registration/trainer/confirm', $confirmation)->assertUnprocessable();
        $this->assertEquals($otherOrganization->id, $user->refresh()->organization_id);
        $this->assertDatabaseHas('wait_confirmation_invitations', ['email' => $user->email, 'confirmed' => false]);
        $this->assertDatabaseMissing('activity_log', ['event' => 'trainer.invitation.accepted', 'causer_id' => $user->id]);
    }

    public function test_wrong_kind_wrong_code_and_attempt_limit_cannot_create_account(): void
    {
        $data = $this->data('trainer');
        $confirmation = $this->start('trainer', $data);
        $this->postJson('/api/mobile/auth/registration/student/confirm', $confirmation)->assertUnprocessable();
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/mobile/auth/registration/trainer/confirm', array_replace($confirmation, ['code' => '000000']))->assertUnprocessable();
        }
        $this->postJson('/api/mobile/auth/registration/trainer/confirm', $confirmation)->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
    }

    public function test_expired_or_revoked_invitation_cannot_be_accepted(): void
    {
        $data = $this->data('trainer');
        $confirmation = $this->start('trainer', $data);
        DB::table('wait_confirmation_invitations')->where('email', $data['email'])->delete();
        $this->postJson('/api/mobile/auth/registration/trainer/confirm', $confirmation)->assertUnprocessable();
        $data = $this->data('student', 'student@example.test');
        $confirmation = $this->start('student', $data);
        $this->travel(11)->minutes();
        $this->postJson('/api/mobile/auth/registration/student/confirm', $confirmation)->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => 'student@example.test']);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    public function test_existing_account_requires_password_and_cannot_change_identity_or_steal_other_roles(): void
    {
        $user = $this->user('Student', ['email' => 'student@example.test']);
        $data = array_replace($this->data('student', $user->email), ['existing_account' => true, 'password' => 'wrong']);
        unset($data['password_confirmation']);
        $this->postJson('/api/mobile/auth/registration/student', $data)->assertUnprocessable();
        $data['password'] = 'safe-password';
        $confirmation = $this->start('student', $data);
        $this->postJson('/api/mobile/auth/registration/student/confirm', $confirmation)->assertOk();
        $this->assertSame('First', $user->refresh()->first_name);
        $this->assertSame(1, User::where('email', $user->email)->count());
        $organization = $this->user('Organization');
        $data['email'] = $organization->email;
        $this->postJson('/api/mobile/auth/registration/student', $data)->assertUnprocessable();
        $this->postJson('/api/mobile/auth/registration/admin', $data)->assertStatus(405);
    }

    public function test_invitation_emails_use_configured_app_links_not_web_registration(): void
    {
        config(['mobile_app.ios_url' => 'https://apps.apple.com/app/id123', 'mobile_app.android_url' => 'https://play.google.com/store/apps/details?id=test']);
        foreach (['ru', 'en'] as $locale) {
            foreach ([new TrainerInvitation($this->user('Organization'), 'KR-CODE', $locale), new StudentInvitation('Coach', 'KR-CODE', $locale)] as $mail) {
                $html = $mail->render();
                $this->assertStringContainsString('KR-CODE', $html);
                $this->assertStringContainsString('https://apps.apple.com/app/id123', $html);
                $this->assertStringContainsString('https://play.google.com/store/apps/details?id=test', $html);
                $this->assertStringNotContainsString('/panel/register', $html);
                $this->assertStringNotContainsString('/student/register', $html);
            }
        }
        config(['mobile_app.ios_url' => null, 'mobile_app.android_url' => 'javascript:alert(1)']);
        $html = (new StudentInvitation('Coach', 'KR-CODE', 'en'))->render();
        $this->assertStringNotContainsString('<a ', $html);
        $this->assertStringContainsString('ask the sender', $html);
    }
}
