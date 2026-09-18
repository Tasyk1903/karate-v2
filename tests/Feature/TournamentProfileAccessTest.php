<?php

namespace Tests\Feature;

use App\Models\MobileAccessToken;
use App\Models\StudentTournament;
use App\Models\User;
use App\Services\Account\CoachProfileAccess;
use App\Services\Students\StudentProfileAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesProfileTournament;
use Tests\TestCase;

class TournamentProfileAccessTest extends TestCase
{
    use CreatesProfileTournament, RefreshDatabase;

    private User $org;

    private User $coach;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 9, 17)->setTime(12, 0));
        foreach (['Organization', 'Secretary', 'Coach', 'Student'] as $role) {
            $id = DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $role]);
        }
        $this->org = $this->user('Organization', ['can_edit_students' => false, 'can_edit_coaches' => false]);
        $this->coach = $this->user('Coach', ['organization_id' => $this->org->id]);
        $this->student = $this->user('Student', ['organization_id' => $this->org->id, 'coach_id' => $this->coach->id,
            'birthday' => '2016-01-01', 'rang' => '5 кю', 'weight' => 30]);
    }

    private function user(string $role, array $extra = []): User
    {
        return User::forceCreate($extra + ['first_name' => 'Alex', 'last_name' => 'Test', 'email' => Str::uuid().'@example.test',
            'password' => 'password', 'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
    }

    private function mobileAs(User $user): void
    {
        $token = Str::random(40);
        MobileAccessToken::create(['user_id' => $user->id, 'name' => 'test', 'token' => hash('sha256', $token), 'expires_at' => now()->addDay()]);
        $this->acceptMobileAgreements($user);
        $this->withToken($token);
    }

    public function test_home_organization_switch_does_not_lock_nonparticipants_or_other_event_participants(): void
    {
        $this->profileTournament($this->org, $this->user('Student'));
        $url = '/api/mobile/students/'.$this->student->id;
        $this->mobileAs($this->student);
        $this->getJson($url)->assertOk()->assertJsonPath('student.capabilities.rang', true);
        $this->postJson($url, ['birthday' => '2015-01-01', 'rang' => '4 кю'])->assertOk();

        $otherOrg = $this->user('Organization', ['can_edit_students' => true, 'can_edit_coaches' => true]);
        $this->profileTournament($otherOrg, $this->student);
        $this->getJson($url)->assertOk()->assertJsonPath('student.capabilities.birthday', true);
        $this->postJson($url, ['rang' => '3 кю'])->assertOk();
        $this->mobileAs($this->coach);
        $this->getJson($url)->assertOk()->assertJsonPath('student.capabilities.rang', true);
        $this->postJson($url, ['birthday' => '2014-01-01', 'rang' => '2 кю'])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $this->student->id, 'birthday' => '2014-01-01', 'rang' => '2 кю']);
    }

    public function test_secretary_switch_locks_only_participants_and_get_matches_post_without_side_effects(): void
    {
        $this->org->forceFill(['can_edit_students' => true, 'can_edit_coaches' => true])->save();
        $this->profileTournament($this->org, $this->student);
        $free = $this->user('Student', ['coach_id' => $this->coach->id, 'organization_id' => $this->org->id]);
        $secretary = $this->user('Secretary', ['organization_id' => $this->org->id]);
        $this->acceptMobileAgreements($secretary);
        $this->actingAs($secretary)->putJson('/api/panel/settings', ['can_edit_students' => false, 'can_edit_coaches' => false])->assertOk();
        $this->assertDatabaseHas('activity_log', ['event' => 'organization.settings.updated', 'causer_id' => $secretary->id, 'subject_id' => $this->org->id]);
        $url = '/api/mobile/students/'.$this->student->id;
        foreach ([$this->student, $this->coach] as $actor) {
            $this->mobileAs($actor);
            $before = $this->student->fresh()->getAttributes();
            $this->getJson($url)->assertOk()->assertJsonPath('student.capabilities.birthday', false)->assertJsonPath('student.capabilities.rang', false);
            $logCount = DB::table('activity_log')->count();
            $this->postJson($url, ['birthday' => '2014-01-01', 'rang' => '3 кю'])->assertUnprocessable();
            $this->assertSame($before, $this->student->fresh()->getAttributes());
            $this->assertSame($logCount, DB::table('activity_log')->count());
        }
        $this->getJson('/api/mobile/students/'.$free->id)->assertOk()->assertJsonPath('student.capabilities.rang', true);
        $this->postJson('/api/mobile/students/'.$free->id, ['birthday' => '2015-02-03', 'rang' => '0 кю'])->assertOk();

        $this->actingAs($secretary)->putJson('/api/panel/settings', ['can_edit_students' => false, 'can_edit_coaches' => true])->assertOk();
        $this->postJson($url, ['rang' => '3 кю'])->assertOk();
        $this->mobileAs($this->student);
        $this->postJson($url, ['rang' => '2 кю'])->assertUnprocessable();
    }

    public function test_actual_event_owner_not_home_organization_controls_restriction_and_any_lock_wins(): void
    {
        $this->org->forceFill(['can_edit_students' => true, 'can_edit_coaches' => true])->save();
        $this->profileTournament($this->org, $this->student);
        $other = $this->user('Organization', ['can_edit_students' => false, 'can_edit_coaches' => false]);
        $tournament = $this->profileTournament($other, $this->student);
        $access = app(StudentProfileAccess::class);
        $this->assertFalse($access->capabilities($this->student, $this->student)['rang']);
        $this->assertFalse($access->capabilities($this->coach, $this->student)['rang']);
        StudentTournament::where('tournament_id', $tournament->id)->delete();
        $this->assertTrue($access->capabilities($this->student, $this->student)['rang']);
        $this->assertTrue($access->capabilities($this->coach, $this->student)['rang']);
    }

    public function test_restriction_ends_after_finish_day_and_ignores_deleted_events_or_championships(): void
    {
        $tournament = $this->profileTournament($this->org, $this->student);
        $tournament->forceFill(['date_finish' => today()->setTime(1, 0)])->save();
        $access = app(StudentProfileAccess::class);
        $this->assertFalse($access->capabilities($this->student, $this->student)['rang']);
        $this->travelTo(today()->setTime(23, 59, 59));
        $this->assertFalse($access->capabilities($this->coach, $this->student)['rang']);
        $this->travelTo(today()->addDay());
        $this->assertTrue($access->capabilities($this->student, $this->student)['rang']);
        $tournament->forceFill(['date_finish' => today()->addDay()])->save();
        $tournament->delete();
        $this->assertTrue($access->capabilities($this->student, $this->student)['rang']);
        $tournament->restore();
        $tournament->championship->delete();
        $this->assertTrue($access->capabilities($this->coach, $this->student)['rang']);
    }

    public function test_coach_is_not_a_participant_just_because_attached_as_a_coach(): void
    {
        $tournament = $this->profileTournament($this->org, $this->student);
        DB::table('tournament_treners')->insert(['tournament_id' => $tournament->id, 'trener_id' => $this->coach->id]);
        $this->mobileAs($this->coach);
        $this->getJson('/api/mobile/trainer/profile')->assertOk()->assertJsonPath('trainer.capabilities.rang', true);
        $this->postJson('/api/mobile/trainer/profile', $this->coach->only(['first_name', 'last_name', 'email']) + [
            'gender' => 'm', 'birthday' => '1980-01-01', 'rang' => '1 дан',
        ])->assertOk();
        StudentTournament::forceCreate(['student_id' => $this->coach->id, 'tournament_id' => $tournament->id]);
        $this->assertFalse(app(CoachProfileAccess::class)->capabilities($this->coach)['rang']);
    }
}
