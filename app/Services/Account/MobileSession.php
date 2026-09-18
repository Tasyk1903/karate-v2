<?php

namespace App\Services\Account;

use App\Models\MobileAccessToken;
use App\Models\User;
use App\Services\Students\StudentProfileSetup;
use App\Services\Team\TeamActivity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class MobileSession
{
    public function issue(User $user, bool $remember = true, string $source = 'login'): array
    {
        abort_unless(app(MobileAppAccess::class)->role($user), 403);

        return DB::transaction(function () use ($user, $remember, $source) {
            $token = Str::random(80);
            $expiresAt = now()->addDays($remember ? 30 : 1);
            MobileAccessToken::query()->create([
                'user_id' => $user->id, 'name' => 'trainer-app',
                'token' => hash('sha256', $token), 'expires_at' => $expiresAt,
            ]);
            TeamActivity::record($user, 'mobile.auth.login', User::class, $user->id,
                ['source' => $source, 'new' => ['remember' => $remember, 'expires_at' => $expiresAt->toISOString()]], logName: 'mobile');

            return ['token' => $token, 'expires_at' => $expiresAt->toISOString(), 'user' => $this->user($user)];
        });
    }

    public function user(User $user): array
    {
        return [
            'id' => $user->id,
            'name' => trim($user->full_name) ?: $user->name,
            'email' => $user->email,
            'avatar_url' => $user->avatar ? asset('storage/'.$user->avatar) : null,
            'roles' => $user->projectRoleNames(),
            'navigation' => app(MobileAppAccess::class)->navigation($user),
            'organization_id' => $user->organization_id,
            'agreements_required' => app(MobileAgreements::class)->required($user),
            'profile_setup_required' => app(StudentProfileSetup::class)->required($user),
        ];
    }
}
