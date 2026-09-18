<?php

namespace App\Services\Team;

use App\Models\User;
use App\Models\WaitConfirmationInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AcceptStudentInvitation
{
    public function prepare(array $data): array
    {
        $email = mb_strtolower(trim($data['email']));
        $coach = app(CoachInvitations::class)->coach($data['coach_code']);
        $user = User::withTrashed()->where('email', $email)->first();
        if ($data['existing_account']) {
            $this->assertAccount($user);
            if (! Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['email' => __('team.account_unavailable')]);
            }
        } elseif ($user) {
            throw ValidationException::withMessages(['email' => __('team.email_exists')]);
        }
        $invitation = DB::transaction(function () use ($coach, $email) {
            User::query()->lockForUpdate()->findOrFail($coach->id);

            return app(CoachInvitations::class)->pending($coach, $email);
        });

        return ['invitation_id' => $invitation->id, 'coach_id' => $coach->id, 'coach_code' => $data['coach_code'],
            'email' => $email, 'user_id' => $user?->id, 'password_hash' => $user?->password ?? Hash::make($data['password']),
            'first_name' => $data['first_name'] ?? '', 'last_name' => $data['last_name'] ?? ''];
    }

    public function accept(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $coach = User::query()->lockForUpdate()->findOrFail($data['coach_id']);
            if (app(CoachInvitations::class)->coach($data['coach_code'])->id !== $coach->id) {
                abort(403);
            }
            $invitation = WaitConfirmationInvitation::query()->lockForUpdate()->find($data['invitation_id']);
            if (! $invitation || $invitation->confirmed || $invitation->target_role !== 'Student'
                || (int) $invitation->inviting_id !== (int) $coach->id || $invitation->email !== $data['email']) {
                throw ValidationException::withMessages(['email' => __('team.invalid_invitation')]);
            }
            $user = User::withTrashed()->where('email', $data['email'])->lockForUpdate()->first();
            if ($data['user_id']) {
                $this->assertAccount($user);
                if ($user->id !== $data['user_id'] || ! hash_equals($user->password, $data['password_hash'])) {
                    throw ValidationException::withMessages(['email' => __('team.account_unavailable')]);
                }
            } elseif ($user) {
                throw ValidationException::withMessages(['email' => __('team.email_exists')]);
            }
            $before = $user?->only(['coach_id', 'email_verified_at']);
            $created = ! $user;
            if (! $user) {
                $user = User::create(['first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                    'name' => trim($data['last_name'].' '.$data['first_name']), 'email' => $data['email'], 'password' => $data['password_hash']]);
                DB::table('model_has_roles')->insert(['role_id' => DB::table('roles')->where('name', 'Student')->where('guard_name', 'web')->firstOrFail()->id, 'model_type' => User::class, 'model_id' => $user->id]);
                $user->forceFill(['student_profile_setup_required' => true]);
            }
            $user->forceFill(['coach_id' => $coach->id, 'email_verified_at' => now()])->save();
            WaitConfirmationInvitation::query()->where('inviting_id', $coach->id)->where('target_role', 'Student')
                ->where('email', $data['email'])->where('confirmed', false)->update(['confirmed' => true, 'accepted_user_id' => $user->id, 'updated_at' => now()]);
            TeamActivity::record($user, 'student.invitation.accepted', WaitConfirmationInvitation::class, $invitation->id,
                ['old' => $before, 'new' => $user->only(['coach_id', 'email_verified_at']), 'created_account' => $created]);
            if ($created) {
                TeamActivity::record($user, 'user.registered', User::class, $user->id,
                    ['old' => null, 'new' => $user->only(['email', 'first_name', 'last_name', 'coach_id']), 'role' => 'Student']);
            }

            return $user;
        }, 3);
    }

    private function assertAccount(?User $user): void
    {
        if (! $user || $user->trashed() || $user->is_external || $user->projectRoleNames() !== ['Student'] || $user->coach_id) {
            throw ValidationException::withMessages(['email' => __('team.account_unavailable')]);
        }
    }
}
