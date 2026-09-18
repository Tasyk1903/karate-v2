<?php

namespace Tests\Feature;

use App\Mail\AccountPasswordReset;
use App\Models\Championship;
use App\Models\Tournament;
use App\Models\User;
use App\Models\UserAlert;
use App\Models\WaitConfirmationInvitation;
use App\Services\Account\SafeContent;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class AccountWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Mail::fake();
        Storage::fake('public');
        foreach (['Organization', 'Secretary', 'Coach', 'Student', 'Judge'] as $name) {
            $id = DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $name]);
        }
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function user(string $role = 'Organization', ?User $organization = null, array $extra = []): User
    {
        return User::create($extra + ['name' => $role, 'first_name' => 'First', 'last_name' => 'Last',
            'email' => Str::uuid().'@example.test', 'password' => 'old-password', 'organization_id' => $organization?->id,
            'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
    }

    private function agreement(int $id = 2): void
    {
        DB::table('agreements')->insert(['id' => $id, 'type' => 'Privacy', 'description' => '<p>Version one</p>', 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_account_endpoints_require_authentication_and_organization_or_secretary(): void
    {
        foreach (['profile', 'dashboard', 'notifications', 'agreements'] as $path) {
            $this->getJson('/api/panel/account/'.$path)->assertUnauthorized();
        }
        foreach (['Coach', 'Judge'] as $role) {
            $this->actingAs($this->user($role));
            foreach (['profile', 'dashboard', 'notifications', 'agreements'] as $path) {
                $this->getJson('/api/panel/account/'.$path)->assertForbidden();
            }
            $this->postJson('/api/panel/account/profile', ['name' => 'No'])->assertForbidden();
            $this->postJson('/api/panel/account/notifications/read-all')->assertForbidden();
            $this->postJson('/api/panel/account/agreements/2/accept', ['accepted' => true])->assertForbidden();
        }
    }

    public function test_profile_only_updates_allowed_own_fields_and_uploads_can_be_replaced_or_removed(): void
    {
        $user = $this->user();
        $foreign = $this->user();
        $this->actingAs($user)->post('/api/panel/account/profile', ['name' => 'My club', 'avatar' => UploadedFile::fake()->image('avatar.png'),
            'email' => 'hijacked@example.test', 'organization_id' => $foreign->id, 'role_id' => 5, 'user_id' => $foreign->id])->assertOk()->assertJsonPath('name', 'My club');
        $avatar = $user->fresh()->avatar;
        Storage::disk('public')->assertExists($avatar);
        $this->assertNull($user->fresh()->organization_id);
        $this->assertEquals($user->role_id, $user->fresh()->role_id);
        $this->assertSame($user->email, $user->fresh()->email);
        $this->assertSame('Organization', $foreign->fresh()->name);
        $this->post('/api/panel/account/profile', ['name' => 'New name', 'avatar' => UploadedFile::fake()->image('second.png')])->assertOk();
        Storage::disk('public')->assertMissing($avatar);
        $replacement = $user->fresh()->avatar;
        $this->postJson('/api/panel/account/profile', ['name' => 'New name', 'remove_avatar' => true])->assertOk()->assertJsonPath('avatar', null);
        Storage::disk('public')->assertMissing($replacement);
        $this->assertDatabaseHas('activity_log', ['event' => 'profile.updated', 'causer_id' => $user->id]);
        $this->post('/api/panel/account/profile', ['name' => 'Invalid', 'avatar' => UploadedFile::fake()->create('x.svg', 1, 'image/svg+xml')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame('New name', $user->fresh()->name);
    }

    public function test_secretary_has_only_name_fields_and_changes_do_not_modify_organization(): void
    {
        $organization = $this->user();
        $secretary = $this->user('Secretary', $organization);
        $this->actingAs($secretary)->getJson('/api/panel/account/profile')->assertOk()->assertJsonPath('is_organization', false)->assertJsonMissingPath('email');
        $this->postJson('/api/panel/account/profile', ['first_name' => 'Anna', 'last_name' => 'New', 'name' => 'Injected'])->assertOk()->assertJsonPath('name', 'New Anna');
        $this->assertSame('Organization', $organization->fresh()->name);
    }

    public function test_notifications_are_paginated_owned_and_read_status_is_individual_and_idempotent(): void
    {
        $user = $this->user();
        $secretary = $this->user('Secretary', $user);
        for ($i = 0; $i < 22; $i++) {
            $alert = UserAlert::create(['message' => '<p>Hello&nbsp;world</p><script>alert(1)</script>']);
            $user->userAlerts()->attach($alert->id);
            $secretary->userAlerts()->attach($alert->id);
        }
        $this->actingAs($user)->getJson('/api/panel/account/notifications')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('unread', 22)->assertJsonPath('last_page', 2);
        $this->getJson('/api/panel/account/notifications?page=2')->assertJsonCount(2, 'data');
        $this->postJson('/api/panel/account/notifications/'.$alert->id.'/read')->assertOk()->assertJsonPath('unread', 21);
        $firstRead = DB::table('user_alert_user')->where('user_id', $user->id)->where('user_alert_id', $alert->id)->value('read_at');
        $this->travel(1)->minutes();
        $this->postJson('/api/panel/account/notifications/'.$alert->id.'/read')->assertOk();
        $this->assertEquals($firstRead, DB::table('user_alert_user')->where('user_id', $user->id)->where('user_alert_id', $alert->id)->value('read_at'));
        $this->postJson('/api/panel/account/notifications/read-all')->assertJsonPath('unread', 0);
        $this->actingAs($secretary)->getJson('/api/panel/account/notifications')->assertJsonPath('unread', 22);
        $this->actingAs($this->user())->postJson('/api/panel/account/notifications/'.$alert->id.'/read')->assertNotFound();
        $this->assertEquals(2, DB::table('activity_log')->where('event', 'notification.read')->count());
    }

    public function test_rich_content_preserves_safe_formatting_and_links_but_not_executable_content(): void
    {
        $html = SafeContent::html('<p onclick="evil()">Hello&nbsp;<b>world</b></p><script>evil()</script><img src=x onerror=evil()><a href="javascript:evil()">bad</a><a href="/admin/users">admin</a><a href="/panel/agreement-doc/2">privacy</a><a href="https://example.test/help">help</a>');
        $this->assertStringContainsString('<b>world</b>', $html);
        $this->assertStringContainsString('/panel/documents/2', $html);
        $this->assertStringContainsString('rel="noopener noreferrer"', $html);
        foreach (['onclick', '<script', '<img', 'javascript:', 'href="/admin'] as $unsafe) {
            $this->assertStringNotContainsString($unsafe, $html);
        }
    }

    public function test_current_agreements_gate_panel_and_consent_is_explicit_versioned_and_repeat_safe(): void
    {
        $this->agreement(2);
        $this->agreement(3);
        $user = $this->user();
        $this->actingAs($user)->getJson('/api/auth/user')->assertJsonPath('user.agreements_required', true);
        $this->getJson('/api/panel/team')->assertStatus(409)->assertJsonPath('code', 'agreements_required');
        $this->getJson('/api/panel/account/dashboard')->assertStatus(409);
        $this->postJson('/api/panel/account/profile', ['name' => 'Blocked'])->assertStatus(409);
        $this->assertSame('Organization', $user->fresh()->name);
        $this->getJson('/api/panel/account/agreements')->assertOk()->assertJsonCount(2, 'data');
        foreach ([2, 3] as $id) {
            $version = $this->getJson('/api/panel/account/agreements/'.$id)->assertOk()->json('version');
            $this->postJson('/api/panel/account/agreements/'.$id.'/accept', ['version' => $version, 'accepted' => false])->assertUnprocessable();
            $this->postJson('/api/panel/account/agreements/'.$id.'/accept', ['version' => $version, 'accepted' => true])->assertOk();
            $this->postJson('/api/panel/account/agreements/'.$id.'/accept', ['version' => $version, 'accepted' => true])->assertOk();
        }
        $this->assertDatabaseCount('agreement_acceptances', 2);
        $this->assertEquals(2, DB::table('activity_log')->where('event', 'agreement.accepted')->count());
        $this->assertTrue((bool) $user->fresh()->data_processing);
        $this->getJson('/api/auth/user')->assertJsonPath('user.agreements_required', false);
        $this->getJson('/api/panel/team')->assertOk();
        $this->getJson('/api/panel/account/agreements/999')->assertNotFound();
        DB::table('agreements')->where('id', 3)->update(['description' => '<p>New version</p>']);
        $this->getJson('/api/auth/user')->assertJsonPath('user.agreements_required', true);
        $this->postJson('/api/panel/account/agreements/3/accept', ['version' => $version, 'accepted' => true, 'locale' => 'en'])->assertUnprocessable();
        $this->assertDatabaseHas('agreement_acceptances', ['content' => '<p>Version one</p>']);
        $this->actingAs($this->user('Secretary', $user))->getJson('/api/panel/account/agreements/3')->assertJsonPath('accepted_at', null);
        $this->postJson('/api/panel/account/agreements', ['description' => 'overwrite'])->assertStatus(405);
    }

    public function test_password_email_is_neutral_throttled_and_contains_a_one_use_token(): void
    {
        $user = $this->user();
        $known = $this->postJson('/api/auth/forgot-password', ['email' => $user->email, 'locale' => 'en'])->assertOk()->json();
        $this->postJson('/api/auth/forgot-password', ['email' => 'missing@example.test', 'locale' => 'en'])->assertExactJson($known);
        $this->postJson('/api/auth/forgot-password', ['email' => $user->email, 'locale' => 'en'])->assertExactJson($known);
        Mail::assertQueued(AccountPasswordReset::class, 1);
        $mail = Mail::queued(AccountPasswordReset::class)->first();
        $this->assertStringContainsString('/reset-password?', $mail->resetUrl);
        $this->assertStringContainsString('Password recovery', $mail->render());
        parse_str(parse_url($mail->resetUrl, PHP_URL_QUERY), $query);
        $this->assertNotSame($query['token'], DB::table('password_reset_tokens')->value('token'));
        DB::table('mobile_access_tokens')->insert(['user_id' => $user->id, 'token' => hash('sha256', 'token'), 'expires_at' => now()->addDay()]);
        $payload = $query + ['password' => 'new-password', 'password_confirmation' => 'new-password', 'locale' => 'en'];
        $this->postJson('/api/auth/reset-password', $payload)->assertOk();
        $this->assertTrue(Hash::check('new-password', $user->fresh()->password));
        $this->assertDatabaseCount('mobile_access_tokens', 0);
        $this->assertDatabaseCount('password_reset_tokens', 0);
        $this->postJson('/api/auth/reset-password', $payload)->assertUnprocessable();
        $this->assertDatabaseHas('activity_log', ['event' => 'password.reset', 'subject_id' => $user->id]);
        $properties = DB::table('activity_log')->where('event', 'password.reset')->value('properties');
        $this->assertStringNotContainsString('new-password', $properties);
        $this->assertStringNotContainsString($query['token'], $properties);
    }

    public function test_password_reset_rejects_expired_wrong_and_mismatched_tokens_and_limits_requests(): void
    {
        $user = $this->user();
        $token = Password::createToken($user);
        $payload = ['email' => $user->email, 'token' => $token, 'password' => 'new-password', 'password_confirmation' => 'different'];
        $this->postJson('/api/auth/reset-password', $payload)->assertUnprocessable();
        $payload['password_confirmation'] = 'new-password';
        $this->postJson('/api/auth/reset-password', array_replace($payload, ['token' => 'wrong']))->assertUnprocessable();
        $this->travel(61)->minutes();
        $this->postJson('/api/auth/reset-password', $payload)->assertUnprocessable();
        $this->assertTrue(Hash::check('old-password', $user->fresh()->password));
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/auth/forgot-password', ['email' => 'none@example.test'])->assertOk();
        }
        $this->postJson('/api/auth/forgot-password', ['email' => 'none@example.test'])->assertTooManyRequests();
    }

    public function test_dashboard_is_scoped_and_secretary_sees_the_same_organization(): void
    {
        $organization = $this->user();
        $foreign = $this->user();
        $coach = $this->user('Coach', $organization);
        $this->user('Coach', $foreign);
        $this->user('Student', $organization, ['coach_id' => $coach->id]);
        WaitConfirmationInvitation::create(['email' => 'pending@example.test', 'organization_id' => $organization->id, 'inviting_id' => $organization->id, 'confirmed' => false]);
        foreach ([$organization, $foreign] as $owner) {
            $championship = Championship::create(['name' => 'Championship', 'banner' => 'banner.jpg', 'organization_id' => $owner->id]);
            for ($i = 0; $i < 8; $i++) {
                Tournament::create(['name' => 'Tournament '.$i, 'championship_id' => $championship->id, 'organization_id' => $owner->id,
                    'tournament_type' => 1, 'age_from' => 8, 'age_to' => 12, 'date_commission' => now(), 'tatami' => 1, 'price' => 0, 'address' => 'Test', 'date' => now()->addDays($i + 1), 'date_finish' => now()->addDays($i + 1)]);
            }
        }
        $data = $this->actingAs($organization)->getJson('/api/panel/account/dashboard')->assertOk()->assertJsonPath('trainers', 1)->assertJsonPath('students', 1)->assertJsonPath('pending', 1)->assertJsonCount(6, 'upcoming')->json();
        $this->actingAs($this->user('Secretary', $organization))->getJson('/api/panel/account/dashboard')->assertExactJson($data);
        $this->assertTrue(collect($data['upcoming'])->every(fn ($item) => Tournament::find($item['id'])->organization_id === $organization->id));
    }

    public function test_reset_revokes_the_current_authenticated_session_and_web_authentication_is_logged(): void
    {
        $user = $this->user();
        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'old-password'])->assertOk();
        $this->assertDatabaseHas('activity_log', ['event' => 'user.login', 'causer_id' => $user->id]);
        $this->postJson('/api/auth/logout')->assertOk();
        $this->assertDatabaseHas('activity_log', ['event' => 'user.logout', 'causer_id' => $user->id]);
        $token = Password::createToken($user);
        $this->actingAs($user)->postJson('/api/auth/reset-password', ['email' => $user->email, 'token' => $token,
            'password' => 'new-password', 'password_confirmation' => 'new-password'])->assertOk();
        $this->assertGuest();
        $this->getJson('/api/auth/user')->assertUnauthorized();
    }
}
