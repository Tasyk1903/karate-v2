<?php

namespace App\Services\Team;

use App\Mail\TrainerInvitation;
use App\Models\User;
use App\Models\WaitConfirmationInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OrganizationInvitations
{
    public function organization(User $actor): User
    {
        abort_unless($actor->hasAnyProjectRole(['Organization', 'Secretary']), 403);
        $organization = $actor->hasProjectRole('Organization') ? $actor : $actor->organization;
        abort_unless($organization?->hasProjectRole('Organization'), 403);

        return $organization;
    }

    public function code(User $actor, ?User $forOrganization = null): string
    {
        if ($forOrganization) {
            abort_unless($actor->hasProjectRole('super_admin') && ! $actor->is_external && $forOrganization->hasProjectRole('Organization'), 403);
        }
        $organization = $forOrganization ?? $this->organization($actor);

        return DB::transaction(function () use ($organization, $actor): string {
            User::query()->lockForUpdate()->findOrFail($organization->id);
            $code = DB::table('organization_join_codes')->where('organization_id', $organization->id)->value('code');
            if (! $code) {
                do {
                    $code = 'KR-'.Str::upper(Str::random(12));
                } while (DB::table('organization_join_codes')->where('code', $code)->exists());
                DB::table('organization_join_codes')->insert([
                    'organization_id' => $organization->id, 'code' => $code,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                TeamActivity::record($actor, 'organization.invitation_code.created', User::class, $organization->id, []);
            }

            return $code;
        });
    }

    public function send(User $actor, array $emails, string $locale): void
    {
        $organization = $this->organization($actor);
        $code = $this->code($actor);
        DB::transaction(function () use ($actor, $organization, $code, $emails, $locale): void {
            User::query()->lockForUpdate()->findOrFail($organization->id);
            foreach ($emails as $email) {
                $invitation = WaitConfirmationInvitation::query()->where('target_role', 'Coach')->where('organization_id', $organization->id)
                    ->where('email', $email)->lockForUpdate()->latest('id')->first();
                if ($invitation?->confirmed) {
                    throw ValidationException::withMessages(['emails' => __('team.invitation_used', [], $locale)]);
                }
                $invitation ??= WaitConfirmationInvitation::query()->where('target_role', 'Coach')->create([
                    'organization_id' => $organization->id, 'inviting_id' => $actor->id,
                    'email' => $email, 'confirmed' => false,
                ]);
                $invitation->touch();
                $this->mail($organization, $code, $email, $locale);
                TeamActivity::record($actor, 'trainer.invitation.sent', WaitConfirmationInvitation::class, $invitation->id,
                    ['organization_id' => $organization->id, 'email' => $email]);
            }
        });
    }

    public function resend(User $actor, WaitConfirmationInvitation $invitation, string $locale): void
    {
        $organization = $this->organization($actor);
        $code = $this->code($actor);
        DB::transaction(function () use ($actor, $invitation, $organization, $code, $locale): void {
            $invitation = WaitConfirmationInvitation::query()->where('target_role', 'Coach')->lockForUpdate()->findOrFail($invitation->id);
            abort_unless($invitation->target_role === 'Coach' && (int) $invitation->organization_id === $organization->id && ! $invitation->confirmed, 403);
            $invitation->touch();
            $this->mail($organization, $code, $invitation->email, $locale);
            TeamActivity::record($actor, 'trainer.invitation.resent', WaitConfirmationInvitation::class, $invitation->id,
                ['organization_id' => $organization->id, 'email' => $invitation->email]);
        });
    }

    private function mail(User $organization, string $code, string $email, string $locale): void
    {
        Mail::to($email)->queue((new TrainerInvitation($organization, $code, $locale))
            ->onConnection('deferred')->afterCommit());
    }

    public function organizationByCode(string $code, bool $lock = false): User
    {
        $organizationId = DB::table('organization_join_codes')->where('code', Str::upper(trim($code)))->value('organization_id');
        $organization = $organizationId ? User::query()->when($lock, fn ($query) => $query->lockForUpdate())->find($organizationId) : null;
        if (! $organization || ! $organization->hasProjectRole('Organization') || $organization->is_external) {
            throw ValidationException::withMessages(['organization_code' => __('team.invalid_organization_code')]);
        }

        return $organization;
    }

    public function pending(User $organization, string $email): WaitConfirmationInvitation
    {
        $invitation = WaitConfirmationInvitation::query()->firstOrCreate([
            'target_role' => 'Coach', 'organization_id' => $organization->id,
            'email' => $email, 'confirmed' => false,
        ], ['inviting_id' => $organization->id]);
        if ($invitation->wasRecentlyCreated) {
            TeamActivity::record(null, 'trainer.invitation.created', WaitConfirmationInvitation::class, $invitation->id,
                ['old' => null, 'new' => ['email' => $email, 'organization_id' => $organization->id, 'confirmed' => false], 'source' => 'registration']);
        }

        return $invitation;
    }
}
