<?php

namespace App\Http\Controllers\Offline;

use App\Http\Controllers\Controller;
use App\Models\MobileAccessToken;
use App\Models\User;
use App\Services\Team\TeamActivity;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

final class OfflineAuthController extends Controller
{
    public function login(Request $request)
    {
        $data = $request->validate(['email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:1024']]);
        $user = User::where('email', $data['email'])->where('is_external', false)->first();
        if (! $user || ! Hash::check($data['password'], $user->password) || ! $user->hasAnyProjectRole(['Organization', 'Secretary'])) {
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }
        $token = Str::random(80);
        DB::transaction(function () use ($user, $token): void {
            MobileAccessToken::create(['user_id' => $user->id, 'name' => 'offline-v1', 'token' => hash('sha256', $token), 'expires_at' => now()->addDays(90)]);
            TeamActivity::record($user, 'offline.login', User::class, $user->id, [], 'offline');
        });

        return response()->json(['token' => $token, 'user' => $this->profile($user)])->header('Cache-Control', 'no-store');
    }

    public function me(Request $request)
    {
        return response()->json(['user' => $this->profile($request->user())]);
    }

    public function logout(Request $request)
    {
        DB::transaction(function () use ($request): void {
            MobileAccessToken::where('token', hash('sha256', $request->bearerToken()))->where('user_id', $request->user()->id)->delete();
            TeamActivity::record($request->user(), 'offline.logout', User::class, $request->user()->id, [], 'offline');
        });

        return response()->json(['ok' => true]);
    }

    private function profile(User $user): array
    {
        return ['id' => $user->id, 'name' => $user->name ?: $user->full_name, 'role' => $user->hasProjectRole('Organization') ? 'Organization' : 'Secretary'];
    }
}
