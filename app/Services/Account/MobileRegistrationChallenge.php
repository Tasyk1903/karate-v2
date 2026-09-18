<?php

namespace App\Services\Account;

use App\Mail\TrainerEmailVerification;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class MobileRegistrationChallenge
{
    public function start(string $kind, array $prepared): string
    {
        $token = Str::random(64);
        $code = (string) random_int(100000, 999999);
        $key = $this->key($token);
        Cache::put($key, [
            'kind' => $kind, 'payload' => Crypt::encryptString(json_encode($prepared, JSON_THROW_ON_ERROR)),
            'code_hash' => Hash::make($code), 'attempts' => 0, 'expires_at' => now()->addMinutes(10)->timestamp,
        ], 600);
        try {
            Mail::to($prepared['email'])->queue((new TrainerEmailVerification($code, app()->getLocale()))->onConnection('deferred'));
        } catch (\Throwable $error) {
            Cache::forget($key);
            throw $error;
        }

        return $token;
    }

    public function consume(string $kind, string $token, string $code, callable $accept): mixed
    {
        $key = $this->key($token);

        // Serialize attempts and consumption across devices; no browser session is required.
        return Cache::lock($key.':lock', 30)->block(5, function () use ($key, $kind, $code, $accept) {
            $challenge = Cache::get($key);
            if (! $challenge || $challenge['kind'] !== $kind || $challenge['expires_at'] <= now()->timestamp || $challenge['attempts'] >= 5) {
                throw ValidationException::withMessages(['code' => __('team.verification_invalid')]);
            }
            $challenge['attempts']++;
            Cache::put($key, $challenge, max(1, $challenge['expires_at'] - now()->timestamp));
            if (! Hash::check($code, $challenge['code_hash'])) {
                throw ValidationException::withMessages(['code' => __('team.verification_invalid')]);
            }
            $result = $accept(json_decode(Crypt::decryptString($challenge['payload']), true, flags: JSON_THROW_ON_ERROR));
            Cache::forget($key);

            return $result;
        });
    }

    private function key(string $token): string
    {
        return 'mobile-registration:'.hash('sha256', $token);
    }
}
