<?php

namespace App\Services\Account;

use App\Mail\TrainerEmailVerification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class RegistrationChallenge
{
    public function start(Request $request, string $kind, array $data): void
    {
        $code = (string) random_int(100000, 999999);
        $request->session()->put($kind.'_registration', ['id' => (string) Str::uuid(), 'data' => $data,
            'code_hash' => Hash::make($code), 'expires_at' => now()->addMinutes(10)->timestamp]);
        Mail::to($data['email'])->queue((new TrainerEmailVerification($code, app()->getLocale()))->onConnection('deferred'));
    }

    public function verify(Request $request, string $kind): array
    {
        $request->validate(['code' => ['required', 'digits:6']]);
        $challenge = $request->session()->get($kind.'_registration');
        $key = $kind.'-registration:'.($challenge['id'] ?? $request->session()->getId());
        if (! $challenge || $challenge['expires_at'] < now()->timestamp || RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages(['code' => __('team.verification_invalid')]);
        }
        RateLimiter::hit($key, 600);
        if (! Hash::check($request->input('code'), $challenge['code_hash'])) {
            throw ValidationException::withMessages(['code' => __('team.verification_invalid')]);
        }

        return $challenge['data'];
    }

    public function clear(Request $request, string $kind): void
    {
        $challenge = $request->session()->pull($kind.'_registration');
        RateLimiter::clear($kind.'-registration:'.($challenge['id'] ?? $request->session()->getId()));
    }
}
