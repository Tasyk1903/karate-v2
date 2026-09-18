<?php

namespace App\Services\Team;

use App\Models\User;
use App\Models\WaitConfirmationInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

final class AcceptTrainerInvitation
{
    public function __construct(private OrganizationInvitations $invitations) {}

    public function prepare(array $data): array
    {
        $email = mb_strtolower(trim($data['email']));
        $organization = $this->invitations->organizationByCode($data['organization_code']);
        $user = User::withTrashed()->where('email', $email)->first();
        if ($data['existing_account']) {
            $this->assertAccount($user, $organization->id);
            if (! Hash::check($data['password'], $user->password)) {
                throw ValidationException::withMessages(['email' => __('team.account_unavailable')]);
            }
        } elseif ($user) {
            throw ValidationException::withMessages(['email' => __('team.email_exists')]);
        }
        $invitation = DB::transaction(function () use ($data, $email) {
            $organization = $this->invitations->organizationByCode($data['organization_code'], lock: true);

            return $this->invitations->pending($organization, $email);
        }, 3);

        return [
            'invitation_id' => $invitation->id, 'organization_id' => $invitation->organization_id,
            'organization_code' => $data['organization_code'], 'email' => $email,
            'user_id' => $user?->id, 'password_hash' => $user?->password ?? Hash::make($data['password']),
            'first_name' => $data['first_name'] ?? '', 'last_name' => $data['last_name'] ?? '',
        ];
    }

    public function accept(array $data): User
    {
        return DB::transaction(function () use ($data): User {
            $organization = $this->invitations->organizationByCode($data['organization_code'], lock: true);
            $invitation = WaitConfirmationInvitation::query()->where('target_role', 'Coach')->lockForUpdate()->find($data['invitation_id']);
            if (! $invitation || $invitation->confirmed || $invitation->email !== $data['email']
                || (int) $invitation->organization_id !== (int) $data['organization_id']
                || $organization->id !== (int) $data['organization_id']) {
                throw ValidationException::withMessages(['email' => __('team.invalid_invitation')]);
            }
            $user = User::withTrashed()->where('email', $data['email'])->lockForUpdate()->first();
            if ($data['user_id']) {
                $this->assertAccount($user, (int) $data['organization_id']);
                if ($user->id !== $data['user_id'] || ! hash_equals($user->password, $data['password_hash'])) {
                    throw ValidationException::withMessages(['email' => __('team.account_unavailable')]);
                }
            } elseif ($user) {
                throw ValidationException::withMessages(['email' => __('team.email_exists')]);
            }
            $before = $user?->only(['organization_id', 'email_verified_at']);
            $created = ! $user;
            if (! $user) {
                $user = User::query()->create([
                    'first_name' => $data['first_name'], 'last_name' => $data['last_name'],
                    'name' => trim($data['first_name'].' '.$data['last_name']),
                    'email' => $data['email'], 'password' => $data['password_hash'],
                ]);
                DB::table('model_has_roles')->insert([
                    'role_id' => DB::table('roles')->where('name', 'Coach')->where('guard_name', 'web')->firstOrFail()->id,
                    'model_type' => User::class, 'model_id' => $user->id,
                ]);
            }
            $user->forceFill(['organization_id' => $invitation->organization_id, 'email_verified_at' => now()])->save();
            // Consume legacy duplicate invitations to the same organization and email together.
            WaitConfirmationInvitation::query()->where('target_role', 'Coach')->where('organization_id', $invitation->organization_id)
                ->where('email', $invitation->email)->where('confirmed', false)
                ->update(['confirmed' => true, 'accepted_user_id' => $user->id, 'updated_at' => now()]);
            TeamActivity::record($user, 'trainer.invitation.accepted', WaitConfirmationInvitation::class, $invitation->id,
                ['organization_id' => $invitation->organization_id, 'created_account' => $created,
                    'old' => $before, 'new' => $user->only(['organization_id', 'email_verified_at'])]);
            if ($created) {
                TeamActivity::record($user, 'user.registered', User::class, $user->id,
                    ['old' => null, 'new' => $user->only(['first_name', 'last_name', 'email', 'organization_id']), 'role' => 'Coach']);
            }

            return $user;
        }, 3);
    }

    private function assertAccount(?User $user, int $organizationId): void
    {
        if (! $user || $user->trashed() || $user->is_external || ! $user->hasProjectRole('Coach')
            || array_diff($user->projectRoleNames(), ['Coach']) !== []
            || ($user->organization_id && (int) $user->organization_id !== $organizationId)) {
            throw ValidationException::withMessages(['email' => __('team.account_unavailable')]);
        }
    }
}
