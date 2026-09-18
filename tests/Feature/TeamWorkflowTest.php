<?php

namespace Tests\Feature;

use App\Mail\TrainerEmailVerification;
use App\Mail\TrainerInvitation;
use App\Models\User;
use App\Models\WaitConfirmationInvitation;
use App\Services\Team\OrganizationInvitations;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class TeamWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Model::unguard();
        Mail::fake();
        Storage::fake('protected');
        foreach (['Organization', 'Secretary', 'Coach', 'Judge', 'Student'] as $name) {
            $id = DB::table('roles')->insertGetId(['name' => $name, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $name]);
        }
    }

    protected function tearDown(): void
    {
        Model::reguard();
        parent::tearDown();
    }

    private function user(string $role, ?User $organization = null, array $extra = []): User
    {
        return User::create(array_merge([
            'first_name' => 'First', 'last_name' => 'Last', 'name' => $role,
            'email' => Str::uuid().'@example.test', 'password' => 'valid-password',
            'role_id' => DB::table('roles')->where('name', $role)->value('id'),
            'organization_id' => $organization?->id,
        ], $extra));
    }

    private function invite(User $actor, string $email = 'coach@example.test'): array
    {
        $service = app(OrganizationInvitations::class);
        $service->send($actor, [$email], 'ru');

        return ['email' => $email, 'organization_code' => $service->code($actor),
            'first_name' => 'Coach', 'last_name' => 'New', 'existing_account' => false,
            'password' => 'new-password', 'password_confirmation' => 'new-password', 'locale' => 'en'];
    }

    private function confirmationCode(): string
    {
        return Mail::queued(TrainerEmailVerification::class)->last()->code;
    }

    public function test_organization_and_secretary_share_code_and_pending_list_without_referral_links(): void
    {
        $organization = $this->user('Organization');
        $secretary = $this->user('Secretary', $organization);
        $code = $this->actingAs($organization)->getJson('/api/panel/team/invitation-code')->assertOk()->json('code');
        $this->actingAs($secretary)->getJson('/api/panel/team/invitation-code')->assertJsonPath('code', $code);
        $this->postJson('/api/panel/team/invite-trainers', ['emails' => ' Coach@Example.test ', 'locale' => 'en'])->assertOk();
        $invitation = WaitConfirmationInvitation::firstOrFail();
        $this->assertEquals($organization->id, $invitation->organization_id);
        $this->assertEquals($secretary->id, $invitation->inviting_id);
        $this->assertSame('coach@example.test', $invitation->email);
        $this->actingAs($organization)->getJson('/api/panel/team?section=pending')->assertJsonPath('stats.pending', 1);
        $this->postJson('/api/panel/team/pending/'.$invitation->id.'/resend')->assertOk();
        Mail::assertQueued(TrainerInvitation::class, 2);
        $mail = Mail::queued(TrainerInvitation::class)->first();
        $html = $mail->render();
        $this->assertStringContainsString($code, $html);
        $this->assertStringNotContainsString('?ref=', $html);
        $this->assertStringNotContainsString('&email=', $html);
        $this->actingAs($this->user('Coach', $organization))->getJson('/api/panel/team/invitation-code')->assertForbidden();
    }

    public function test_new_coach_verifies_email_and_accepts_secretary_invitation_only_once(): void
    {
        $organization = $this->user('Organization');
        $data = $this->invite($this->user('Secretary', $organization));
        $this->postJson('/api/auth/trainer-registration', $data)->assertOk()->assertJsonPath('verification_required', true);
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
        $this->postJson('/api/auth/trainer-registration/confirm', ['code' => $this->confirmationCode()])->assertOk();
        $coach = User::where('email', $data['email'])->firstOrFail();
        $this->assertTrue($coach->hasProjectRole('Coach'));
        $this->assertEquals($organization->id, $coach->organization_id);
        $this->assertNotNull($coach->email_verified_at);
        $this->assertTrue(Hash::check('new-password', $coach->password));
        $this->assertAuthenticatedAs($coach);
        $this->assertDatabaseHas('wait_confirmation_invitations', ['email' => $data['email'], 'confirmed' => true, 'accepted_user_id' => $coach->id]);
        $this->assertDatabaseHas('activity_log', ['event' => 'user.registered', 'subject_id' => $coach->id]);
        Auth::logout();
        $this->postJson('/api/auth/trainer-registration', $data)->assertUnprocessable();
        $this->actingAs($organization)->getJson('/api/panel/team?section=pending')->assertJsonPath('stats.pending', 0)->assertJsonPath('stats.trainers', 1);
    }

    public function test_existing_coach_must_authenticate_and_is_not_duplicated_or_reset(): void
    {
        $organization = $this->user('Organization');
        $coach = $this->user('Coach', null, ['email' => 'coach@example.test', 'password' => 'old']);
        $data = $this->invite($organization);
        $data['existing_account'] = true;
        $data['password_confirmation'] = $data['password'] = 'wrong-password';
        $this->postJson('/api/auth/trainer-registration', $data)->assertUnprocessable();
        $data['password_confirmation'] = $data['password'] = 'old';
        $this->postJson('/api/auth/trainer-registration', $data)->assertOk();
        $before = $coach->password;
        $this->postJson('/api/auth/trainer-registration/confirm', ['code' => $this->confirmationCode()])->assertOk();
        $this->assertEquals($organization->id, $coach->refresh()->organization_id);
        $this->assertSame($before, $coach->password);
        $this->assertSame('First', $coach->first_name);
        $this->assertEquals(1, User::where('email', $coach->email)->count());
    }

    public function test_foreign_coaches_and_other_roles_cannot_be_reassigned(): void
    {
        $organization = $this->user('Organization');
        foreach (['Coach', 'Judge', 'Secretary', 'Student'] as $role) {
            $account = $this->user($role, $this->user('Organization'));
            $data = $this->invite($organization, $account->email);
            $data['existing_account'] = true;
            $data['password_confirmation'] = $data['password'] = 'valid-password';
            $this->postJson('/api/auth/trainer-registration', $data)->assertUnprocessable();
            $this->assertNotEquals($organization->id, $account->refresh()->organization_id);
        }
        Mail::assertNotQueued(TrainerEmailVerification::class);
    }

    public function test_code_selects_organization_without_matching_email_invitation(): void
    {
        $data = $this->invite($this->user('Organization'));
        $organization = $this->user('Organization');
        $data['organization_code'] = app(OrganizationInvitations::class)->code($organization);
        $this->postJson('/api/auth/trainer-registration', $data)->assertOk();
        $this->postJson('/api/auth/trainer-registration/confirm', ['code' => $this->confirmationCode()])->assertOk();
        $this->assertDatabaseHas('users', ['email' => $data['email'], 'organization_id' => $organization->id]);
        $this->assertSame(1, WaitConfirmationInvitation::where('email', $data['email'])->where('confirmed', false)->count());
    }

    public function test_invalid_code_and_ref_parameter_cannot_register_a_coach(): void
    {
        $data = $this->invite($this->user('Organization'));
        $data['organization_code'] = 'KR-INVALID';
        $this->postJson('/api/auth/trainer-registration', $data)->assertUnprocessable();
        unset($data['organization_code']);
        $data['ref'] = 'legacy-ref-token';
        $this->postJson('/api/auth/trainer-registration', $data)->assertUnprocessable();
        Mail::assertNotQueued(TrainerEmailVerification::class);
    }

    public function test_verification_code_expires_and_rejected_attempts_do_not_create_accounts(): void
    {
        $data = $this->invite($this->user('Organization'));
        $this->postJson('/api/auth/trainer-registration', $data)->assertOk();
        $this->postJson('/api/auth/trainer-registration/confirm', ['code' => '000000'])->assertUnprocessable();
        $this->travel(11)->minutes();
        $this->postJson('/api/auth/trainer-registration/confirm', ['code' => $this->confirmationCode()])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
        $this->assertDatabaseHas('wait_confirmation_invitations', ['confirmed' => false]);
    }

    public function test_verification_stops_after_five_wrong_attempts(): void
    {
        $data = $this->invite($this->user('Organization'));
        $this->postJson('/api/auth/trainer-registration', $data)->assertOk();
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/api/auth/trainer-registration/confirm', ['code' => '000000'])->assertUnprocessable();
        }
        $this->postJson('/api/auth/trainer-registration/confirm', ['code' => $this->confirmationCode()])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
    }

    public function test_revoking_invitation_during_verification_prevents_acceptance(): void
    {
        $organization = $this->user('Organization');
        $data = $this->invite($organization);
        $this->postJson('/api/auth/trainer-registration', $data)->assertOk();
        $this->actingAs($organization)->deleteJson('/api/panel/team/pending/'.WaitConfirmationInvitation::first()->id)->assertOk();
        Auth::logout();
        $this->postJson('/api/auth/trainer-registration/confirm', ['code' => $this->confirmationCode()])->assertUnprocessable();
        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
    }

    public function test_single_and_bulk_deletion_preserve_references_and_log_each_account(): void
    {
        $organization = $this->user('Organization');
        $secretary = $this->user('Secretary', $organization);
        $invitationData = $this->invite($secretary);
        $this->actingAs($organization)->deleteJson('/api/panel/team/secretaries/members', ['ids' => [$secretary->id]])->assertOk();
        $this->assertSoftDeleted('users', ['id' => $secretary->id]);
        $this->assertDatabaseCount('wait_confirmation_invitations', 1);
        $this->getJson('/api/panel/team?section=pending')->assertJsonPath('stats.pending', 1);
        $judges = [$this->user('Judge', $organization)->id, $this->user('Judge', $organization)->id];
        $this->deleteJson('/api/panel/team/judges/members', ['ids' => $judges])->assertOk();
        $this->getJson('/api/panel/team?section=judges')->assertJsonPath('stats.judges', 0);
        $this->assertEquals(3, DB::table('activity_log')->where('event', 'team.member.deleted')->count());
        Auth::logout();
        $this->postJson('/api/auth/trainer-registration', $invitationData)->assertOk();
    }

    public function test_bulk_delete_is_atomic_for_foreign_members_and_rejects_secretary_and_linked_accounts(): void
    {
        $organization = $this->user('Organization');
        $judge = $this->user('Judge', $organization);
        $foreign = $this->user('Judge', $this->user('Organization'));
        $this->actingAs($this->user('Secretary', $organization))->deleteJson('/api/panel/team/judges/members', ['ids' => [$judge->id]])->assertForbidden();
        $this->actingAs($organization)->deleteJson('/api/panel/team/judges/members', ['ids' => [$judge->id, $foreign->id]])->assertForbidden();
        $this->assertNotSoftDeleted('users', ['id' => $judge->id]);
        $this->user('Student', $organization, ['coach_id' => $judge->id]);
        $this->deleteJson('/api/panel/team/judges/members', ['ids' => [$judge->id]])->assertUnprocessable();
        $this->assertDatabaseMissing('activity_log', ['event' => 'team.member.deleted']);
    }

    public function test_trainer_documents_are_private_and_secretary_cannot_detach_students(): void
    {
        $organization = $this->user('Organization');
        $coach = $this->user('Coach', $organization, ['passport' => 'passport/coach.png']);
        $student = $this->user('Student', $organization, ['coach_id' => $coach->id]);
        Storage::disk('protected')->put('passport/coach.png', 'synthetic-document');
        $url = '/api/panel/team/trainers/'.$coach->id;
        $response = $this->actingAs($this->user('Secretary', $organization))->getJson($url)->assertOk()
            ->assertJsonCount(4, 'documents')->assertJsonPath('capabilities.detach_students', false);
        $this->get($response->json('documents.0.file'))->assertOk();
        $this->deleteJson($url.'/students/'.$student->id)->assertForbidden();
        $this->actingAs($organization)->getJson($url)->assertJsonPath('capabilities.detach_students', true);
        $this->actingAs($this->user('Organization'))->get($response->json('documents.0.file'))->assertForbidden();
        $this->getJson($url)->assertForbidden();
        $this->actingAs($this->user('Coach', $organization))->getJson($url)->assertForbidden();
        $this->actingAs($this->user('Judge', $organization))->getJson($url)->assertForbidden();
    }
}
