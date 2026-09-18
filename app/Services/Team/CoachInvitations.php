<?php

namespace App\Services\Team;

use App\Mail\StudentInvitation;
use App\Models\User;
use App\Models\WaitConfirmationInvitation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class CoachInvitations
{
    public function code(User $coach): string
    {
        abort_unless($coach->hasProjectRole('Coach') && ! $coach->is_external, 403);

        return DB::transaction(function () use ($coach) {
            User::query()->lockForUpdate()->findOrFail($coach->id);
            $code = DB::table('coach_join_codes')->where('coach_id', $coach->id)->value('code');
            if (! $code) {
                do {
                    $code = 'KR-C'.Str::upper(Str::random(12));
                } while (DB::table('coach_join_codes')->where('code', $code)->exists());
                DB::table('coach_join_codes')->insert(['coach_id' => $coach->id, 'code' => $code, 'created_at' => now(), 'updated_at' => now()]);
                TeamActivity::record($coach, 'coach.invitation_code.created', User::class, $coach->id, []);
            }

            return $code;
        }, 3);
    }

    public function coach(string $code): User
    {
        $id = DB::table('coach_join_codes')->where('code', Str::upper(trim($code)))->value('coach_id');
        $coach = $id ? User::find($id) : null;
        if (! $coach || ! $coach->hasProjectRole('Coach') || $coach->is_external) {
            throw ValidationException::withMessages(['coach_code' => __('mobile_students.invalid_code')]);
        }

        return $coach;
    }

    public function pending(User $coach, string $email, ?User $actor = null): WaitConfirmationInvitation
    {
        $invitation = WaitConfirmationInvitation::query()->firstOrCreate([
            'inviting_id' => $coach->id, 'target_role' => 'Student', 'email' => $email, 'confirmed' => false,
        ], ['organization_id' => null]);
        if ($invitation->wasRecentlyCreated) {
            TeamActivity::record($actor, 'student.invitation.created', WaitConfirmationInvitation::class, $invitation->id,
                ['old' => null, 'new' => ['email' => $email, 'coach_id' => $coach->id, 'confirmed' => false], 'source' => $actor ? 'coach' : 'registration']);
        }

        return $invitation;
    }

    public function send(User $coach, array $emails, string $locale): array
    {
        $code = $this->code($coach);
        $results = [];
        foreach (array_unique(array_map(fn ($email) => mb_strtolower(trim($email)), $emails)) as $email) {
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $results[] = ['email' => $email, 'status' => 'invalid_email'];

                continue;
            }
            try {
                $status = DB::transaction(function () use ($coach, $email, $code, $locale) {
                    User::query()->lockForUpdate()->findOrFail($coach->id);
                    $existing = User::withTrashed()->where('email', $email)->first();
                    if ($existing && ($existing->trashed() || $existing->is_external || $existing->projectRoleNames() !== ['Student'] || $existing->coach_id)) {
                        return (int) $existing->coach_id === (int) $coach->id ? 'already_attached' : 'unavailable';
                    }
                    $invitation = $this->pending($coach, $email, $coach);
                    Mail::to($email)->queue((new StudentInvitation($coach->full_name, $code, $locale))->onConnection('deferred')->afterCommit());
                    TeamActivity::record($coach, 'student.invitation.queued', WaitConfirmationInvitation::class, $invitation->id,
                        ['old' => null, 'new' => ['email' => $email, 'confirmed' => false], 'coach_id' => $coach->id]);

                    return 'queued';
                }, 3);
            } catch (\Throwable $error) {
                report($error);
                $status = 'failed';
            }
            $results[] = ['email' => $email, 'status' => $status];
        }

        return $results;
    }
}
