<?php

namespace App\Services\Account;

use App\Models\User;
use App\Services\Team\TeamActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

final class StudentAccountRestore
{
    private function eligible(User $user): bool
    {
        if (! $user->trashed() || $user->is_external || $user->projectRoleNames() !== ['Student']) {
            return false;
        }
        $lastDeletion = DB::table('activity_log')->where('subject_type', User::class)->where('subject_id', $user->id)
            ->where('event', 'like', '%deleted')->orderByDesc('id')->first();

        return $lastDeletion && (int) $lastDeletion->causer_id === (int) $user->id
            && in_array($lastDeletion->event, ['mobile.account.deleted', 'student.account.deleted'], true);
    }

    public function request(string $email): void
    {
        $key = 'student-restore:'.hash('sha256', mb_strtolower($email));
        if (RateLimiter::tooManyAttempts($key, 3)) {
            return;
        }
        RateLimiter::hit($key, 3600);
        $user = User::onlyTrashed()->where('email', $email)->first();
        if (! $user || ! $this->eligible($user)) {
            return;
        }
        $code = (string) random_int(100000, 999999);
        DB::table('account_restore_challenges')->updateOrInsert(['user_id' => $user->id],
            ['code_hash' => Hash::make($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(15)]);
        Mail::raw(__('student_restore.mail', ['code' => $code]), fn ($mail) => $mail->to($user->email)->subject(__('student_restore.title')));
        TeamActivity::record($user, 'student.restore.requested', User::class, $user->id, ['old' => null, 'new' => ['expires_at' => now()->addMinutes(15)->toISOString()]]);
    }

    public function confirm(string $email, string $code, string $password): void
    {
        $restored = DB::transaction(function () use ($email, $code, $password): bool {
            $user = User::withTrashed()->where('email', $email)->lockForUpdate()->first();
            if (! $user || ! $this->eligible($user)) {
                return false;
            }
            $challenge = DB::table('account_restore_challenges')->where('user_id', $user->id)->lockForUpdate()->first();
            if (! $challenge || now()->gte($challenge->expires_at) || $challenge->attempts >= 5) {
                return false;
            }
            DB::table('account_restore_challenges')->where('user_id', $user->id)->increment('attempts');
            if (! Hash::check($code, $challenge->code_hash)) {
                return false;
            }
            $old = $user->deleted_at?->toISOString();
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => null, 'email_verified_at' => now()]);
            $user->restore();
            DB::table('mobile_access_tokens')->where('user_id', $user->id)->delete();
            DB::table('sessions')->where('user_id', $user->id)->delete();
            DB::table('account_restore_challenges')->where('user_id', $user->id)->delete();
            TeamActivity::record($user, 'student.account.restored', User::class, $user->id, ['self' => true, 'old' => ['deleted_at' => $old], 'new' => ['deleted_at' => null]]);

            return true;
        }, 3);
        if (! $restored) {
            throw ValidationException::withMessages(['code' => __('student_restore.invalid')]);
        }
    }
}
