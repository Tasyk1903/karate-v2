<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\AccountPasswordReset;
use App\Models\User;
use App\Services\Team\TeamActivity;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class PasswordRecoveryController extends Controller
{
    public function request(Request $request): JsonResponse
    {
        $this->locale($request);
        $data = $request->validate(['email' => ['required', 'email', 'max:255']]);
        Password::sendResetLink($data + ['is_external' => false], function (User $user, string $token): void {
            $url = rtrim(config('app.url'), '/').'/reset-password?'.http_build_query(['token' => $token, 'email' => $user->email]);
            Mail::to($user->email)->queue((new AccountPasswordReset($url, app()->getLocale()))->onConnection('deferred')->afterCommit());
            TeamActivity::record(null, 'password.recovery_requested', User::class, $user->id, ['channel' => 'email']);
        });

        // Do not reveal whether an email exists or was recently requested.
        return response()->json(['message' => __('account.reset_sent')]);
    }

    public function reset(Request $request): JsonResponse
    {
        $this->locale($request);
        $data = $request->validate(['email' => ['required', 'email'], 'token' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'min:8', 'max:255', 'confirmed']]);
        // Lock the token row so concurrent requests cannot consume the same token twice.
        $status = DB::transaction(function () use ($data) {
            DB::table(config('auth.passwords.users.table'))->where('email', $data['email'])->lockForUpdate()->first();

            return Password::reset($data + ['is_external' => false], function (User $user, string $password): void {
                $user->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
                DB::table('sessions')->where('user_id', $user->id)->delete();
                DB::table('mobile_access_tokens')->where('user_id', $user->id)->delete();
                DB::table('personal_access_tokens')->where('tokenable_type', User::class)->where('tokenable_id', $user->id)->delete();
                TeamActivity::record($user, 'password.reset', User::class, $user->id, ['old' => ['password_set' => true], 'new' => ['password_changed' => true, 'sessions_revoked' => true]]);
                event(new PasswordReset($user));
            });
        });
        if ($status !== Password::PASSWORD_RESET) {
            throw ValidationException::withMessages(['token' => __('account.reset_invalid')]);
        }
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => __('account.reset_done')]);
    }

    private function locale(Request $request): void
    {
        app()->setLocale($request->input('locale') === 'en' ? 'en' : 'ru');
    }
}
