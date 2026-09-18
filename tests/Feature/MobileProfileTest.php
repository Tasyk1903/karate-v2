<?php

namespace Tests\Feature;

use App\Models\Championship;
use App\Models\MobileAccessToken;
use App\Models\StudentTournament;
use App\Models\Tournament;
use App\Models\User;
use App\Services\Account\CoachProfileAccess;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesProfileTournament;
use Tests\TestCase;

class MobileProfileTest extends TestCase
{
    use CreatesProfileTournament;
    use RefreshDatabase;

    private User $org;

    private User $coach;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Organization', 'Coach', 'Student'] as $role) {
            $id = DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $role]);
        }
        $this->org = $this->user('Organization', ['can_edit_coaches' => true]);
        $this->coach = $this->user('Coach', ['organization_id' => $this->org->id,
            'patronymic' => 'Ivanovich', 'birthday' => '1986-02-03', 'rang' => '1 dan',
            'gender' => 'm', 'weight' => 80, 'club' => 'DOJO']);
        MobileAccessToken::create(['user_id' => $this->coach->id, 'name' => 'test',
            'token' => hash('sha256', 'profile-token'), 'expires_at' => now()->addDay()]);
        $this->acceptMobileAgreements($this->coach);
        $this->withToken('profile-token');
    }

    private function user(string $role, array $attributes = []): User
    {
        $user = User::forceCreate($attributes + ['first_name' => 'Ivan', 'last_name' => 'Test',
            'email' => uniqid().'@example.test', 'password' => Hash::make('password'),
            'role_id' => DB::table('roles')->where('name', $role)->value('id')]);

        return $user;
    }

    private function payload(array $overrides = []): array
    {
        return $overrides + $this->coach->only(['first_name', 'last_name', 'email', 'gender', 'patronymic', 'birthday', 'rang', 'weight']);
    }

    public function test_mobile_locale_applies_to_get_update_validation_and_shared_about(): void
    {
        $this->withHeader('Accept-Language', 'en')->getJson('/api/mobile/trainer/profile')
            ->assertOk()->assertJsonPath('trainer.gender_label', 'Male');
        $this->postJson('/api/mobile/trainer/profile', $this->payload(['email' => 'bad']))
            ->assertUnprocessable()->assertJsonPath('errors.email.0', 'The email field must be a valid email address.');
        $mobile = $this->getJson('/api/mobile/about')->assertOk()->json();
        $this->assertSame('About Karaterating', $mobile['project']['title']);
        $this->assertArrayNotHasKey('stats', $mobile);
        $this->acceptMobileAgreements($this->org);
        $this->actingAs($this->org)->getJson('/api/panel/about')->assertOk()->assertExactJson($mobile);
        $this->withHeader('Accept-Language', 'ru')->getJson('/api/mobile/trainer/profile')
            ->assertOk()->assertJsonPath('trainer.gender_label', 'Мужской');
    }

    public function test_profile_round_trip_preserves_patronymic_and_legacy_rank(): void
    {
        $this->getJson('/api/mobile/trainer/profile')->assertOk()
            ->assertJsonPath('trainer.patronymic', 'Ivanovich')
            ->assertJsonPath('trainer.capabilities.rang', true);
        $this->postJson('/api/mobile/trainer/profile', $this->payload(['birthday' => '03.02.1986']))
            ->assertOk()->assertJsonPath('trainer.patronymic', 'Ivanovich')->assertJsonPath('trainer.rang', '1 dan');
        $payload = $this->payload(['first_name' => 'Petr', 'height' => 181, 'club' => 'New club']);
        unset($payload['patronymic']);
        $this->postJson('/api/mobile/trainer/profile', $payload)->assertOk();
        $this->assertDatabaseHas('users', ['id' => $this->coach->id, 'patronymic' => 'Ivanovich', 'height' => 181, 'club' => 'New club']);
        $this->assertDatabaseHas('activity_log', ['event' => 'mobile.trainer.profile.updated', 'causer_id' => $this->coach->id]);
    }

    public function test_field_capabilities_reject_only_forbidden_changes_and_mass_assignment(): void
    {
        $this->org->forceFill(['can_edit_coaches' => false])->save();
        $this->profileTournament($this->org, $this->coach);
        $this->getJson('/api/mobile/trainer/profile')->assertOk()->assertJsonPath('trainer.capabilities.rang', false);
        foreach (['rang' => '2 дан', 'birthday' => '1987-02-03'] as $field => $value) {
            $this->postJson('/api/mobile/trainer/profile', $this->payload([$field => $value]))->assertUnprocessable()->assertJsonValidationErrors($field);
        }
        $this->postJson('/api/mobile/trainer/profile', $this->payload(['first_name' => 'Allowed', 'height' => 181,
            'role_id' => $this->org->role_id, 'organization_id' => null, 'can_edit_coaches' => true]))->assertOk();
        $this->assertDatabaseHas('users', ['id' => $this->coach->id, 'first_name' => 'Allowed',
            'height' => 181, 'role_id' => $this->coach->role_id, 'organization_id' => $this->org->id]);
    }

    public function test_invalid_dates_and_ranks_are_not_silently_normalized(): void
    {
        foreach (['31.02.2000', '2000-2-03', '2100-01-01'] as $birthday) {
            $this->postJson('/api/mobile/trainer/profile', $this->payload(['birthday' => $birthday]))->assertUnprocessable()->assertJsonValidationErrors('birthday');
        }
        $this->postJson('/api/mobile/trainer/profile', $this->payload(['rang' => '22 кю']))->assertUnprocessable();
        $this->postJson('/api/mobile/trainer/profile', $this->payload(['rang' => '0 кю']))->assertOk();
    }

    public function test_personal_tournament_participation_locks_weight_through_finish_day(): void
    {
        $champ = Championship::forceCreate(['name' => 'Test', 'banner' => 'test.jpg', 'organization_id' => $this->org->id]);
        $tournament = Tournament::forceCreate(['name' => 'Test', 'organization_id' => $this->org->id,
            'championship_id' => $champ->id, 'tournament_type' => Tournament::KUMITE,
            'age_from' => 0, 'age_to' => 100, 'tatami' => 1, 'price' => 0, 'address' => 'Test',
            'date' => today(), 'date_commission' => today(), 'date_finish' => today()]);
        DB::table('tournament_treners')->insert(['tournament_id' => $tournament->id, 'trener_id' => $this->coach->id]);
        $access = app(CoachProfileAccess::class);
        $this->assertTrue($access->capabilities($this->coach)['weight']);
        StudentTournament::forceCreate(['student_id' => $this->coach->id, 'tournament_id' => $tournament->id]);
        $this->assertFalse($access->capabilities($this->coach)['weight']);
        $this->postJson('/api/mobile/trainer/profile', $this->payload(['weight' => 90]))->assertUnprocessable()->assertJsonValidationErrors('weight');
        $this->postJson('/api/mobile/trainer/profile', $this->payload(['patronymic' => 'Petrovich']))->assertOk();
        $champ->delete();
        $this->assertTrue($access->capabilities($this->coach)['weight']);
        $champ->restore();
        $tournament->forceFill(['date_finish' => today()->subDay()])->save();
        $this->assertTrue($access->capabilities($this->coach)['weight']);
    }

    public function test_failed_update_cleans_uploaded_avatar_and_replacement_cleans_old_file(): void
    {
        Storage::fake('public');
        $this->org->forceFill(['can_edit_coaches' => false])->save();
        $this->profileTournament($this->org, $this->coach);
        $this->post('/api/mobile/trainer/profile', $this->payload(['rang' => '2 дан', 'avatar' => UploadedFile::fake()->image('photo.png')]), ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame([], Storage::disk('public')->allFiles());
        foreach (range(1, 2) as $_) {
            $this->post('/api/mobile/trainer/profile', $this->payload(['avatar' => UploadedFile::fake()->image('photo.png')]), ['Accept' => 'application/json'])->assertOk();
            $this->assertCount(1, Storage::disk('public')->allFiles());
        }
    }

    public function test_delete_requires_permission_password_and_confirmation(): void
    {
        $this->postJson('/api/mobile/account/delete', ['password' => 'wrong', 'confirmed' => true])->assertUnprocessable();
        $this->postJson('/api/mobile/account/delete', ['password' => 'password'])->assertUnprocessable();
        $this->org->forceFill(['can_edit_coaches' => false])->save();
        $this->profileTournament($this->org, $this->coach);
        $this->postJson('/api/mobile/account/delete', ['password' => 'password', 'confirmed' => true])->assertForbidden();
        $this->assertFalse($this->coach->fresh()->trashed());
    }

    public function test_delete_revokes_devices_preserves_students_and_organization_read_access(): void
    {
        $student = $this->user('Student', ['coach_id' => $this->coach->id]);
        MobileAccessToken::create(['user_id' => $this->coach->id, 'name' => 'other', 'token' => hash('sha256', 'other'), 'expires_at' => now()->addDay()]);
        $this->postJson('/api/mobile/account/delete', ['password' => 'password', 'confirmed' => true])->assertOk();
        $this->assertSoftDeleted('users', ['id' => $this->coach->id]);
        $this->assertDatabaseMissing('mobile_access_tokens', ['user_id' => $this->coach->id]);
        $this->assertDatabaseHas('users', ['id' => $student->id, 'coach_id' => $this->coach->id, 'deleted_at' => null]);
        $this->assertDatabaseHas('activity_log', ['event' => 'mobile.account.deleted', 'causer_id' => $this->coach->id]);
        $this->getJson('/api/mobile/auth/user')->assertUnauthorized();
        $this->withToken('other')->getJson('/api/mobile/auth/user')->assertUnauthorized();
        $this->acceptMobileAgreements($this->org);
        $this->actingAs($this->org)->getJson('/api/panel/team/students/'.$student->id)->assertOk()->assertJsonPath('student.club', 'DOJO');
        $foreign = $this->user('Organization');
        $this->acceptMobileAgreements($foreign);
        $this->actingAs($foreign)->getJson('/api/panel/team/students/'.$student->id)->assertForbidden();
    }
}
