<?php

namespace Tests\Feature;

use App\Models\MobileAccessToken;
use App\Models\User;
use App\Services\Team\CoachInvitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesProfileTournament;
use Tests\TestCase;

class StudentJoinCoachTest extends TestCase
{
    use CreatesProfileTournament, RefreshDatabase;

    private User $student;

    private User $oldCoach;

    private User $coach;

    private string $code;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        foreach (['Organization', 'Coach', 'Student', 'Judge'] as $role) {
            $id = DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $role]);
        }
        $oldOrg = $this->user('Organization');
        $newOrg = $this->user('Organization');
        $this->oldCoach = $this->user('Coach', ['organization_id' => $oldOrg->id]);
        $this->coach = $this->user('Coach', ['organization_id' => $newOrg->id, 'club' => 'New club', 'can_attach_to_examination_for_students' => true]);
        $this->student = $this->user('Student', ['organization_id' => $oldOrg->id, 'coach_id' => null,
            'birthday' => '2010-01-02', 'weight' => 42, 'rang' => '3 кю', 'passport' => 'passport/kept.jpg',
            'is_success_passport' => true, 'competitive_record_starts_at' => '2020-01-01']);
        $this->code = app(CoachInvitations::class)->code($this->coach);
        Storage::disk('protected')->put('passport/kept.jpg', 'original-document');
        $this->login($this->student);
    }

    private function user(string $role, array $values = []): User
    {
        return User::forceCreate($values + ['name' => 'Test '.$role, 'first_name' => 'First', 'last_name' => $role,
            'email' => uniqid().'@example.test', 'password' => Hash::make('test-password'),
            'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
    }

    private function login(User $user): void
    {
        $token = 'join-test-'.$user->id;
        MobileAccessToken::firstOrCreate(['token' => hash('sha256', $token)], ['user_id' => $user->id, 'name' => 'test', 'expires_at' => now()->addDay()]);
        $this->acceptMobileAgreements($user);
        $this->withToken($token);
    }

    private function payload(): array
    {
        return ['coach_code' => strtolower($this->code), 'coach_id' => $this->coach->id, 'confirmed' => true];
    }

    public function test_join_preserves_account_files_history_session_and_updates_access_without_mail(): void
    {
        $oldOrg = User::findOrFail($this->student->organization_id);
        $this->profileTournament($oldOrg, $this->student);
        $history = DB::table('student_tournaments')->where('student_id', $this->student->id)->get()->toJson();
        $before = $this->student->fresh()->getAttributes();
        $count = User::count();
        $pending = app(CoachInvitations::class)->pending($this->coach, $this->student->email);
        $this->getJson('/api/mobile/students/'.$this->student->id)->assertOk()->assertJsonPath('student.capabilities.join_coach', true);
        $preview = $this->postJson('/api/mobile/account/coach/preview', $this->payload())->assertOk()->json('coach');
        $this->assertSame(['id', 'name', 'club'], array_keys($preview));
        $this->assertSame('New club', $preview['club']);
        $this->assertNull($this->student->fresh()->coach_id);
        $this->postJson('/api/mobile/account/coach/join', $this->payload() + ['student_id' => $this->oldCoach->id])->assertOk()
            ->assertJsonPath('joined', true)->assertJsonPath('user.id', $this->student->id)
            ->assertJsonPath('user.organization_id', $this->coach->organization_id);
        $after = $this->student->fresh();
        $this->assertEquals(collect($before)->except(['coach_id', 'organization_id', 'updated_at'])->all(), collect($after->getAttributes())->except(['coach_id', 'organization_id', 'updated_at'])->all());
        $this->assertSame($count, User::count());
        $this->assertSame($history, DB::table('student_tournaments')->where('student_id', $this->student->id)->get()->toJson());
        $this->assertTrue((bool) $pending->fresh()->confirmed);
        $this->assertEquals($this->student->id, $pending->fresh()->accepted_user_id);
        Storage::disk('protected')->assertExists('passport/kept.jpg');
        $this->getJson('/api/mobile/auth/user')->assertOk();
        $this->getJson('/api/mobile/students/'.$this->student->id)->assertOk()->assertJsonPath('student.club', 'New club')->assertJsonPath('student.capabilities.join_coach', false);
        $this->postJson('/api/mobile/account/coach/join', $this->payload())->assertOk();
        $this->assertSame(1, DB::table('activity_log')->where('event', 'student.coach.joined')->count());
        $event = DB::table('activity_log')->where('event', 'student.coach.joined')->first();
        $this->assertEquals($this->student->id, $event->causer_id);
        $this->assertEquals($this->student->id, $event->target_user_id);
        $this->assertStringNotContainsString($this->code, $event->properties);
        $this->login($this->oldCoach);
        $this->getJson('/api/mobile/students/'.$this->student->id)->assertForbidden();
        $this->getJson('/api/mobile/files/users/'.$this->student->id.'/passport')->assertForbidden();
        $this->login($this->coach);
        $this->getJson('/api/mobile/students/'.$this->student->id)->assertOk()->assertJsonPath('student.capabilities.edit', true);
        Mail::assertNothingOutgoing();
    }

    public function test_existing_coach_must_detach_before_preview_and_join(): void
    {
        $this->student->forceFill(['coach_id' => $this->oldCoach->id])->save();
        $this->getJson('/api/mobile/students/'.$this->student->id)->assertJsonPath('student.capabilities.join_coach', false);
        foreach (['preview', 'join'] as $action) {
            $this->postJson('/api/mobile/account/coach/'.$action, $this->payload())->assertConflict();
        }
        $this->login($this->oldCoach);
        $this->postJson('/api/mobile/students/'.$this->student->id.'/detach', ['confirmed' => true])->assertOk();
        $this->login($this->student);
        $this->postJson('/api/mobile/account/coach/preview', $this->payload())->assertOk();
        $this->postJson('/api/mobile/account/coach/join', $this->payload())->assertOk();
    }

    public function test_commit_rechecks_membership_code_coach_and_confirmation(): void
    {
        $this->postJson('/api/mobile/account/coach/preview', $this->payload())->assertOk();
        $this->postJson('/api/mobile/account/coach/join', array_replace($this->payload(), ['confirmed' => false]))->assertUnprocessable();
        $this->postJson('/api/mobile/account/coach/join', array_replace($this->payload(), ['coach_id' => $this->oldCoach->id]))->assertConflict();
        $this->postJson('/api/mobile/account/coach/preview', ['coach_code' => 'bad-code'])->assertUnprocessable();
        $this->student->forceFill(['coach_id' => $this->oldCoach->id])->save();
        $this->postJson('/api/mobile/account/coach/join', $this->payload())->assertConflict();
        $this->student->forceFill(['coach_id' => null])->save();
        $this->coach->forceFill(['is_external' => true])->save();
        $this->postJson('/api/mobile/account/coach/join', $this->payload())->assertUnprocessable();
        $this->coach->forceFill(['is_external' => false])->save();
        $this->coach->delete();
        $this->postJson('/api/mobile/account/coach/join', $this->payload())->assertUnprocessable();
        $this->assertNull($this->student->fresh()->coach_id);
        $this->assertDatabaseMissing('activity_log', ['event' => 'student.coach.joined']);
    }

    public function test_other_roles_and_anonymous_requests_cannot_join(): void
    {
        $this->withToken('invalid');
        $this->postJson('/api/mobile/account/coach/join', $this->payload())->assertUnauthorized();
        foreach ([$this->oldCoach, $this->user('Judge')] as $actor) {
            $this->login($actor);
            foreach (['preview', 'join'] as $action) {
                $this->postJson('/api/mobile/account/coach/'.$action, $this->payload())->assertForbidden();
            }
        }
        $this->assertNull($this->student->fresh()->coach_id);
    }

    public function test_pivot_student_role_is_supported(): void
    {
        $this->student->forceFill(['role_id' => null])->save();
        DB::table('model_has_roles')->insert(['role_id' => DB::table('roles')->where('name', 'Student')->value('id'), 'model_type' => User::class, 'model_id' => $this->student->id]);
        $this->postJson('/api/mobile/account/coach/join', $this->payload())->assertOk();
    }

    public function test_failed_audit_rolls_back_join_and_pending_invitation(): void
    {
        $pending = app(CoachInvitations::class)->pending($this->coach, $this->student->email);
        $originalOrg = $this->student->organization_id;
        $fail = true;
        DB::connection()->beforeExecuting(function ($sql, $bindings) use (&$fail): void {
            if ($fail && str_contains($sql, 'insert into "activity_log"') && in_array('student.coach.joined', $bindings, true)) {
                throw new \RuntimeException('Simulated audit failure');
            }
        });
        $this->postJson('/api/mobile/account/coach/join', $this->payload())->assertServerError();
        $fail = false;
        $this->assertNull($this->student->fresh()->coach_id);
        $this->assertEquals($originalOrg, $this->student->fresh()->organization_id);
        $this->assertFalse((bool) $pending->fresh()->confirmed);
        $this->assertDatabaseMissing('activity_log', ['event' => 'student.coach.joined']);
    }
}
