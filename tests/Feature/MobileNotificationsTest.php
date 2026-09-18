<?php

namespace Tests\Feature;

use App\Models\MobileAccessToken;
use App\Models\User;
use App\Models\UserAlert;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class MobileNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private User $coach;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        $role = DB::table('roles')->insertGetId(['name' => 'Coach', 'guard_name' => 'web']);
        DB::table('old_roles')->insert(['id' => $role, 'name' => 'Coach']);
        $this->coach = User::create(['name' => 'Coach', 'email' => 'coach@example.test', 'password' => 'password', 'role_id' => $role]);
        $this->other = User::create(['name' => 'Other', 'email' => 'other@example.test', 'password' => 'password', 'role_id' => $role]);
        MobileAccessToken::create(['user_id' => $this->coach->id, 'name' => 'test', 'token' => hash('sha256', 'notice-test'), 'expires_at' => now()->addMonth()]);
        $this->acceptMobileAgreements($this->coach);
        $this->withToken('notice-test');
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function alert(string $message = 'Message', ?User $user = null): UserAlert
    {
        $alert = UserAlert::create(['message' => $message]);
        $alert->users()->attach(($user ?? $this->coach)->id);

        return $alert;
    }

    public static function mobileRoles(): array
    {
        return [['Coach'], ['Student']];
    }

    private function useRole(string $role): void
    {
        if ($role === 'Student') {
            $id = DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $role]);
            $this->coach->update(['role_id' => $id]);
        }
    }

    #[DataProvider('mobileRoles')]
    public function test_history_is_paginated_stably_and_only_for_current_recipient_without_auto_read(string $role): void
    {
        $this->useRole($role);
        $ids = [];
        for ($i = 0; $i < 45; $i++) {
            $ids[] = $this->alert()->id;
        }
        $foreign = $this->alert('Private', $this->other);
        $this->getJson('/api/mobile/notifications/unread')->assertOk()->assertExactJson(['unread' => 45]);
        $a = $this->getJson('/api/mobile/notifications')->assertOk()->assertJsonCount(20, 'data')->assertJsonPath('meta.last_page', 3)->assertJsonPath('meta.unread', 45);
        $b = $this->getJson('/api/mobile/notifications?page=2')->assertOk()->assertJsonCount(20, 'data');
        $c = $this->getJson('/api/mobile/notifications?page=3')->assertOk()->assertJsonCount(5, 'data');
        $actual = collect([...$a['data'], ...$b['data'], ...$c['data']])->pluck('id')->all();
        $this->assertSame(array_reverse($ids), $actual);
        $this->assertNotContains($foreign->id, $actual);
        $this->assertSame(45, $this->coach->userAlerts()->wherePivotNull('read_at')->count());
        $this->getJson('/api/mobile/notifications?per_page=100000')->assertUnprocessable();
        $this->getJson('/api/mobile/notifications?page=0')->assertUnprocessable();
    }

    public function test_structured_message_decodes_entities_preserves_links_and_drops_active_content(): void
    {
        $this->alert('<p>Привет&nbsp;&mdash; &#1040; &#x1F44D; &quot;тест&quot;</p><p><a href="https://example.test/page?a=1&amp;b=2">Подробнее</a><br>Вторая строка</p><script>alert(1)</script><img src=x onerror=alert(2)><a href="javascript:alert(3)">Плохой</a><a href="//evil.test">Скрытый</a><a href="/panel/agreement-doc/7">Соглашение</a>');
        $data = $this->getJson('/api/mobile/notifications')->assertOk()['data'][0];
        $this->assertStringContainsString('Привет — А 👍 "тест"', $data['message']);
        $this->assertStringContainsString("Подробнее\nВторая строка", $data['message']);
        $this->assertStringNotContainsString('<', $data['message']);
        $this->assertStringNotContainsString('alert(', $data['message']);
        $links = collect($data['content'])->pluck('href')->filter()->values()->all();
        $this->assertSame(['https://example.test/page?a=1&b=2', '/panel/documents/7'], $links);
        $this->assertArrayNotHasKey('is_important', $data);
        $this->assertArrayNotHasKey('title', $data);
    }

    #[DataProvider('mobileRoles')]
    public function test_individual_read_is_idempotent_audited_and_does_not_change_other_recipient(string $role): void
    {
        $this->useRole($role);
        $alert = $this->alert();
        $alert->users()->attach($this->other->id);
        $this->postJson('/api/mobile/notifications/'.$alert->id.'/read')->assertOk()->assertJsonPath('unread', 0)->assertJsonPath('changed', 1);
        $read = $this->coach->userAlerts()->first()->pivot->read_at;
        $this->travel(1)->hours();
        $this->postJson('/api/mobile/notifications/'.$alert->id.'/read')->assertOk()->assertJsonPath('changed', 0);
        $this->assertSame($read, $this->coach->userAlerts()->first()->pivot->read_at);
        $this->assertNull($this->other->userAlerts()->first()->pivot->read_at);
        $log = DB::table('activity_log')->where('event', 'mobile.notification.read')->get();
        $this->assertCount(1, $log);
        $props = json_decode($log[0]->properties, true);
        $this->assertNull($props['old']['read_at']);
        $this->assertNotNull($props['new']['read_at']);
        $this->getJson('/api/mobile/notifications')->assertJsonPath('data.0.is_read', true);
    }

    public function test_read_all_updates_every_page_not_other_users_and_is_idempotent(): void
    {
        for ($i = 0; $i < 35; $i++) {
            $this->alert();
        }
        $this->alert('Foreign', $this->other);
        $this->postJson('/api/mobile/notifications/read-all')->assertOk()->assertJsonPath('unread', 0)->assertJsonPath('changed', 35);
        $this->postJson('/api/mobile/notifications/read-all')->assertOk()->assertJsonPath('changed', 0);
        $this->assertSame(1, $this->other->userAlerts()->wherePivotNull('read_at')->count());
        $this->assertSame(1, DB::table('activity_log')->where('event', 'mobile.notifications.read_all')->count());
        $this->getJson('/api/mobile/notifications?page=2')->assertJsonPath('data.0.is_read', true);
    }

    public function test_foreign_read_is_rejected_before_change_or_audit_and_auth_is_required(): void
    {
        $alert = $this->alert('Foreign', $this->other);
        $this->postJson('/api/mobile/notifications/'.$alert->id.'/read')->assertNotFound();
        $this->assertNull($this->other->userAlerts()->first()->pivot->read_at);
        $this->assertSame(0, DB::table('activity_log')->where('event', 'like', 'mobile.notification%')->count());
        $this->withToken('invalid')->getJson('/api/mobile/notifications/unread')->assertUnauthorized();
    }

    public function test_failed_audit_rolls_back_read_state(): void
    {
        $alert = $this->alert();
        DB::statement("CREATE TRIGGER reject_notice_audit BEFORE INSERT ON activity_log BEGIN SELECT RAISE(ABORT, 'test audit failure'); END");
        $this->postJson('/api/mobile/notifications/'.$alert->id.'/read')->assertStatus(500);
        $this->assertNull($this->coach->userAlerts()->first()->pivot->read_at);
        $this->getJson('/api/mobile/notifications/unread')->assertJsonPath('unread', 1);
    }
}
