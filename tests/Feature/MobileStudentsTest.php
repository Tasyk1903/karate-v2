<?php

namespace Tests\Feature;

use App\Mail\StudentInvitation;
use App\Mail\TrainerEmailVerification;
use App\Models\MobileAccessToken;
use App\Models\User;
use App\Models\WaitConfirmationInvitation;
use App\Services\ProtectedMedia;
use App\Services\Students\StudentDocumentStatus;
use App\Services\Team\AcceptStudentInvitation;
use App\Services\Team\CoachInvitations;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\CreatesProfileTournament;
use Tests\TestCase;

class MobileStudentsTest extends TestCase
{
    use CreatesProfileTournament;
    use RefreshDatabase;

    private User $org;

    private User $coach;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['Organization', 'Coach', 'Student'] as $role) {
            $id = DB::table('roles')->insertGetId(['name' => $role, 'guard_name' => 'web']);
            DB::table('old_roles')->insert(['id' => $id, 'name' => $role]);
        }
        $this->org = $this->user('Organization', ['can_edit_coaches' => true]);
        $this->coach = $this->user('Coach', ['organization_id' => $this->org->id, 'club' => 'Coach club']);
        $this->student = $this->user('Student', ['coach_id' => $this->coach->id, 'club' => 'Wrong club', 'birthday' => '2015-01-02', 'rang' => '5 кю', 'weight' => 35]);
        MobileAccessToken::create(['user_id' => $this->coach->id, 'name' => 'test', 'token' => hash('sha256', 'students-test'), 'expires_at' => now()->addDay()]);
        $this->acceptMobileAgreements($this->coach);
        $this->withToken('students-test');
        Mail::fake();
    }

    private function user(string $role, array $data = []): User
    {
        return User::forceCreate($data + ['first_name' => 'Ivan', 'last_name' => 'Example', 'email' => uniqid().'@example.test', 'password' => Hash::make('password'), 'role_id' => DB::table('roles')->where('name', $role)->value('id')]);
    }

    private function url(?User $student = null): string
    {
        return '/api/mobile/students/'.($student ?? $this->student)->id;
    }

    public function test_private_profile_and_all_document_urls_are_own_only(): void
    {
        $other = $this->user('Coach', ['organization_id' => $this->org->id]);
        $foreign = $this->user('Student', ['coach_id' => $other->id]);
        Storage::fake('protected');
        foreach (ProtectedMedia::DOCUMENTS as $field) {
            $path = $field.'/private.png';
            Storage::disk('protected')->put($path, 'private');
            $foreign->forceFill([$field => $path])->save();
            $this->getJson(app(ProtectedMedia::class)->documentUrl($foreign, $field, true))->assertForbidden();
        }
        $this->getJson($this->url($foreign))->assertForbidden();
        $this->postJson($this->url($foreign), ['weight' => 44])->assertForbidden();
        $this->getJson($this->url())->assertOk()->assertJsonPath('student.capabilities.edit', true)->assertJsonPath('student.club', 'Coach club');
    }

    public function test_field_permissions_validation_and_unrelated_updates(): void
    {
        $this->org->forceFill(['can_edit_coaches' => false])->save();
        $this->profileTournament($this->org, $this->student);
        $this->getJson($this->url())->assertJsonPath('student.capabilities.rang', false);
        foreach (['birthday' => '2014-01-02', 'rang' => '4 кю'] as $key => $value) {
            $this->postJson($this->url(), [$key => $value])->assertUnprocessable()->assertJsonValidationErrors($key);
        }
        $this->postJson($this->url(), ['birthday' => '02.01.2015', 'rang' => '5 кю', 'weight' => 40, 'height' => 150])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $this->student->id, 'weight' => 40, 'height' => 150]);
        $this->postJson($this->url(), ['is_success_passport' => true])->assertUnprocessable();
        $this->postJson($this->url(), ['email' => $this->coach->email])->assertUnprocessable();
        $this->org->forceFill(['can_edit_coaches' => true])->save();
        foreach (['31.02.2015', '2015-2-01', '2100-01-01'] as $date) {
            $this->postJson($this->url(), ['birthday' => $date])->assertUnprocessable();
        }
        $this->postJson($this->url(), ['rang' => '99 кю'])->assertUnprocessable();
        $this->postJson($this->url(), ['rang' => '0 кю'])->assertOk();
    }

    public function test_documents_replace_remove_and_rollback_without_public_files(): void
    {
        Storage::fake('protected');
        Storage::fake('public');
        $this->org->forceFill(['can_edit_coaches' => false])->save();
        $this->profileTournament($this->org, $this->student);
        $this->post($this->url(), ['rang' => '4 кю', 'passport' => UploadedFile::fake()->image('x.png')], ['Accept' => 'application/json'])->assertUnprocessable();
        $this->assertSame([], Storage::disk('protected')->allFiles());
        foreach (ProtectedMedia::DOCUMENTS as $field) {
            $old = $field.'/old.png';
            Storage::disk('protected')->put($old, 'old');
            $this->student->forceFill([$field => $old, 'is_success_'.$field => true])->save();
            $this->post($this->url(), [$field => UploadedFile::fake()->image('new.png')], ['Accept' => 'application/json'])->assertOk();
            Storage::disk('protected')->assertMissing($old);
            $new = $this->student->fresh()->$field;
            Storage::disk('protected')->assertExists($new);
            $this->assertFalse((bool) $this->student->fresh()->{'is_success_'.$field});
            $this->postJson($this->url(), ['remove_documents' => [$field]])->assertOk();
            $this->assertNull($this->student->fresh()->$field);
            Storage::disk('protected')->assertMissing($new);
        }
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertDatabaseHas('activity_log', ['event' => 'mobile.student.updated', 'causer_id' => $this->coach->id]);
    }

    public function test_database_failure_keeps_old_private_document_and_removes_new_upload(): void
    {
        Storage::fake('protected');
        Storage::fake('public');
        Storage::disk('public')->put('passport/old.png', 'old');
        $this->student->forceFill(['passport' => 'passport/old.png'])->save();
        DB::statement('CREATE TRIGGER reject_student_update BEFORE UPDATE ON users WHEN NEW.id = '.$this->student->id." BEGIN SELECT RAISE(ABORT, 'test failure'); END");
        try {
            $this->post($this->url(), ['passport' => UploadedFile::fake()->image('new.png')], ['Accept' => 'application/json'])->assertStatus(500);
        } finally {
            DB::statement('DROP TRIGGER reject_student_update');
        }
        $this->assertSame('passport/old.png', $this->student->fresh()->passport);
        $this->assertSame(['passport/old.png'], Storage::disk('protected')->allFiles());
        Storage::disk('public')->assertMissing('passport/old.png');
    }

    public function test_legacy_document_is_not_made_public_when_its_reference_is_removed(): void
    {
        Storage::fake('protected');
        Storage::fake('public');
        Storage::disk('public')->put('legacy/custom.png', 'private-document');
        $this->student->forceFill(['passport' => 'legacy/custom.png'])->save();
        $this->postJson($this->url(), ['remove_documents' => ['passport']])->assertOk();
        Storage::disk('public')->assertMissing('legacy/custom.png');
        Storage::disk('protected')->assertMissing('legacy/custom.png');
        $this->get('/storage/legacy/custom.png')->assertNotFound();
    }

    public function test_existing_unattached_student_can_confirm_and_keep_personal_data(): void
    {
        $free = $this->user('Student', ['birthday' => '2010-01-01', 'rang' => '2 дан']);
        $data = $this->registration(['email' => $free->email, 'existing_account' => true]);
        unset($data['first_name'], $data['last_name'], $data['password_confirmation']);
        $this->postJson('/api/auth/student-registration', $data)->assertOk();
        $mail = Mail::queued(TrainerEmailVerification::class)->last();
        $this->postJson('/api/auth/student-registration/confirm', ['code' => $mail->code])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $free->id, 'coach_id' => $this->coach->id, 'birthday' => '2010-01-01', 'rang' => '2 дан']);
    }

    public function test_shared_file_is_preserved_and_expiry_exclusion_status_matches_list(): void
    {
        Storage::fake('protected');
        Storage::disk('protected')->put('passport/shared.png', 'shared');
        $this->student->forceFill(['passport' => 'passport/shared.png'])->save();
        $this->user('Student', ['passport' => 'passport/shared.png']);
        $this->postJson($this->url(), ['remove_documents' => ['passport']])->assertOk();
        Storage::disk('protected')->assertExists('passport/shared.png');
        $this->student->forceFill(['is_success_passport' => true, 'is_success_brand' => true, 'is_success_insurance' => true, 'insurance_close_date' => today()->subDay(), 'is_iko_card_included_check' => false, 'is_certificate_included_check' => false])->save();
        $status = app(StudentDocumentStatus::class)->evaluate($this->student->fresh());
        $this->assertSame(['documentIssueInsuranceExpired'], $status['issues']);
        $this->getJson('/api/mobile/students?search=Example%20Ivan')->assertOk()->assertJsonPath('data.0.documents_ok', false)->assertJsonPath('data.0.weight', 35)->assertJsonPath('data.0.document_issues', $status['issues']);
        $this->getJson($this->url())->assertJsonPath('documents.items.3.included', false)->assertJsonPath('documents.items.3.ok', true);
    }

    public function test_detach_requires_confirmation_and_keeps_account(): void
    {
        $this->postJson($this->url().'/detach')->assertUnprocessable();
        $this->postJson($this->url().'/detach', ['confirmed' => true])->assertOk();
        $this->assertDatabaseHas('users', ['id' => $this->student->id, 'coach_id' => null, 'deleted_at' => null]);
        $this->getJson($this->url())->assertForbidden();
        $this->postJson($this->url().'/detach', ['confirmed' => true])->assertForbidden();
    }

    public function test_invitation_code_is_stable_bulk_outcomes_pending_cancellation_and_type_isolation(): void
    {
        $first = $this->getJson('/api/mobile/student-invitations')->assertOk()->json('code');
        $this->getJson('/api/mobile/student-invitations')->assertJsonPath('code', $first);
        $this->postJson('/api/mobile/student-invitations', ['emails' => [' NEW@example.test ', 'bad', $this->student->email]])->assertOk()
            ->assertJsonPath('results.0.status', 'queued')->assertJsonPath('results.1.status', 'invalid_email')->assertJsonPath('results.2.status', 'already_attached');
        Mail::assertQueued(StudentInvitation::class, fn ($mail) => $mail->coachCode === $first);
        $row = WaitConfirmationInvitation::where('target_role', 'Student')->firstOrFail();
        WaitConfirmationInvitation::create(['inviting_id' => $this->coach->id, 'target_role' => 'Coach', 'email' => 'not-a-student@example.test', 'confirmed' => false]);
        $this->getJson('/api/mobile/student-invitations')->assertJsonPath('meta.total', 1);
        $wrong = WaitConfirmationInvitation::create(['inviting_id' => $this->org->id, 'target_role' => 'Student', 'email' => 'other@example.test', 'confirmed' => false]);
        $this->deleteJson('/api/mobile/student-invitations/'.$wrong->id)->assertForbidden();
        $this->deleteJson('/api/mobile/student-invitations/'.$row->id)->assertOk();
        $this->getJson('/api/mobile/student-invitations')->assertJsonPath('meta.total', 0);
        $this->assertDatabaseMissing('users', ['email' => 'new@example.test']);
    }

    private function registration(array $extra = []): array
    {
        return $extra + ['coach_code' => app(CoachInvitations::class)->code($this->coach), 'email' => 'register@example.test', 'existing_account' => false, 'password' => 'password', 'password_confirmation' => 'password', 'first_name' => 'New', 'last_name' => 'Student'];
    }

    public function test_code_registration_verifies_email_before_attaching_and_prevents_reuse(): void
    {
        $this->postJson('/api/auth/student-registration', $this->registration())->assertOk()->assertJsonPath('verification_required', true);
        $this->assertDatabaseMissing('users', ['email' => 'register@example.test']);
        $mail = Mail::queued(TrainerEmailVerification::class)->last();
        $this->postJson('/api/auth/student-registration/confirm', ['code' => 'wrong'])->assertUnprocessable();
        $this->postJson('/api/auth/student-registration/confirm', ['code' => $mail->code])->assertOk()->assertJsonPath('registered', true);
        $user = User::where('email', 'register@example.test')->firstOrFail();
        $this->assertTrue($user->hasProjectRole('Student'));
        $this->assertSame($this->coach->id, (int) $user->coach_id);
        $this->assertNotNull($user->email_verified_at);
        $this->assertDatabaseHas('wait_confirmation_invitations', ['email' => $user->email, 'target_role' => 'Student', 'confirmed' => true]);
        $this->postJson('/api/auth/student-registration/confirm', ['code' => $mail->code])->assertUnprocessable();
    }

    public function test_existing_accounts_need_password_and_cannot_be_transferred_or_escalated(): void
    {
        foreach ([$this->student, $this->org, $this->coach] as $user) {
            $this->postJson('/api/auth/student-registration', $this->registration(['email' => $user->email, 'existing_account' => true]))->assertUnprocessable();
        }
        $free = $this->user('Student');
        $this->postJson('/api/auth/student-registration', $this->registration(['email' => $free->email, 'existing_account' => true, 'password' => 'wrong', 'password_confirmation' => 'wrong']))->assertUnprocessable();
        $data = app(AcceptStudentInvitation::class)->prepare($this->registration(['email' => $free->email, 'existing_account' => true]));
        WaitConfirmationInvitation::findOrFail($data['invitation_id'])->delete();
        $this->expectException(ValidationException::class);
        app(AcceptStudentInvitation::class)->accept($data);
    }
}
