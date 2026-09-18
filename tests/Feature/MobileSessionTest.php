<?php

namespace Tests\Feature;

use App\Models\MobileAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class MobileSessionTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Coach', 'Student', 'Secretary'] as $role) {
            $id = DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $role]);
        }
        $this->coach = User::forceCreate([
            'first_name' => 'Coach', 'last_name' => 'Test',
            'email' => 'coach@example.test', 'password' => Hash::make('password'),
            'role_id' => DB::table('roles')->where('name', 'Coach')->value('id'),
            'is_external' => false,
        ]);
    }

    private function token(string $plain, array $attributes = []): MobileAccessToken
    {
        return MobileAccessToken::create($attributes + [
            'user_id' => $this->coach->id, 'name' => 'test',
            'token' => hash('sha256', $plain), 'expires_at' => now()->addDay(),
        ]);
    }

    public function test_login_stores_only_hash_and_returns_current_coach(): void
    {
        $login = $this->postJson('/api/mobile/auth/login', [
            'email' => $this->coach->email, 'password' => 'password', 'remember' => false,
        ])->assertOk()->assertJsonPath('user.id', $this->coach->id);
        $plain = $login->json('token');
        $menu = $login->json('user.navigation.menu');
        $this->assertNotContains('payments', $menu);
        $this->assertSame(['agreements', 'settings', 'about', 'logout'], array_slice($menu, -4));
        $this->assertDatabaseHas('mobile_access_tokens', ['token' => hash('sha256', $plain)]);
        $this->assertDatabaseMissing('mobile_access_tokens', ['token' => $plain]);
        $this->withToken($plain)->getJson('/api/mobile/auth/user')->assertOk()
            ->assertJsonPath('user.roles.0', 'Coach');
        $this->assertStringNotContainsString($plain, DB::table('activity_log')->get()->toJson());
        $this->assertDatabaseHas('activity_log', ['event' => 'mobile.auth.login', 'causer_id' => $this->coach->id]);
    }

    public function test_logout_revokes_only_current_token_and_logs_once(): void
    {
        $this->token('current');
        $other = $this->token('other-device');
        $this->withToken('current')->postJson('/api/mobile/auth/logout')->assertOk();
        $this->assertDatabaseMissing('mobile_access_tokens', ['token' => hash('sha256', 'current')]);
        $this->assertDatabaseHas('mobile_access_tokens', ['id' => $other->id]);
        $this->withToken('current')->getJson('/api/mobile/auth/user')->assertUnauthorized();
        $this->withToken('current')->postJson('/api/mobile/auth/logout')->assertUnauthorized();
        $this->assertSame(1, DB::table('activity_log')->where('event', 'mobile.auth.logout')->count());
        $this->withToken('other-device')->getJson('/api/mobile/auth/user')->assertOk();
    }

    public function test_missing_expired_revoked_deleted_user_and_changed_role_fail_closed(): void
    {
        $this->getJson('/api/mobile/auth/user')->assertUnauthorized();
        $this->token('expired', ['expires_at' => now()->subSecond()]);
        $this->withToken('expired')->getJson('/api/mobile/auth/user')->assertUnauthorized();
        $this->withToken('unknown')->getJson('/api/mobile/auth/user')->assertUnauthorized();
        $this->token('changed');
        $this->coach->forceFill(['role_id' => DB::table('roles')->where('name', 'Secretary')->value('id')])->save();
        $this->withToken('changed')->getJson('/api/mobile/auth/user')->assertUnauthorized();
        $this->withToken('changed')->getJson('/api/mobile/feed')->assertUnauthorized();
        // Revocation remains available even after the coach role is removed.
        $this->withToken('changed')->postJson('/api/mobile/auth/logout')->assertOk();
        $this->token('deleted');
        $this->coach->delete();
        $this->withToken('deleted')->getJson('/api/mobile/auth/user')->assertUnauthorized();
    }

    public function test_external_user_cannot_restore_or_obtain_a_coach_session(): void
    {
        $this->token('external');
        $this->coach->forceFill(['is_external' => true])->save();
        $this->withToken('external')->getJson('/api/mobile/auth/user')->assertUnauthorized();
        $this->postJson('/api/mobile/auth/login', [
            'email' => $this->coach->email, 'password' => 'password',
        ])->assertUnprocessable();
    }

    public function test_student_login_navigation_self_profile_and_coach_routes_are_separated(): void
    {
        $student = User::forceCreate(['first_name' => 'Student', 'last_name' => 'Test', 'email' => 'student@example.test',
            'password' => Hash::make('password'), 'role_id' => DB::table('roles')->where('name', 'Student')->value('id'),
            'coach_id' => $this->coach->id, 'rang' => '5 кю', 'birthday' => '2014-01-01', 'is_external' => false]);
        $this->acceptMobileAgreements($student);
        $login = $this->postJson('/api/mobile/auth/login', ['email' => $student->email, 'password' => 'password'])->assertOk();
        $this->assertSame(['rating', 'feed', 'profile'], $login->json('user.navigation.bottom'));
        $this->assertSame(['tournaments', 'agreements', 'about', 'logout'], $login->json('user.navigation.menu'));
        $this->withToken($login->json('token'))->getJson('/api/mobile/auth/user')->assertOk()->assertJsonPath('user.roles.0', 'Student');
        $this->getJson('/api/mobile/students/'.$student->id)->assertOk()->assertJsonPath('student.capabilities.detach', false);
        $this->getJson('/api/mobile/students/'.$this->coach->id)->assertNotFound();
        $other = $student->replicate()->forceFill(['email' => 'other@example.test']);
        $other->save();
        $this->getJson('/api/mobile/students/'.$other->id)->assertForbidden();
        $this->postJson('/api/mobile/students/'.$other->id, ['weight' => 50])->assertForbidden();
        $this->getJson('/api/mobile/students/'.$other->id.'/history?kind=wins')->assertForbidden();
        foreach (['students', 'student-invitations', 'trainer/profile', 'trainer/settings', 'quick-fights'] as $path) {
            $this->getJson('/api/mobile/'.$path)->assertUnauthorized();
        }
        $this->getJson('/api/mobile/examinations')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/mobile/education/works')->assertForbidden();
        $this->getJson('/api/mobile/feed?scope=students')->assertUnprocessable();
        $this->getJson('/api/mobile/feed?scope=all')->assertOk();
        $this->getJson('/api/mobile/notifications/unread')->assertOk();
        $this->getJson('/api/mobile/about')->assertOk();
        $this->postJson('/api/mobile/auth/logout')->assertOk();
        $this->getJson('/api/mobile/auth/user')->assertUnauthorized();
    }
}
