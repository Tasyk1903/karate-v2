<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Account\Agreements;
use App\Services\PanelAccess;
use App\Services\Team\TeamActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LoginController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ]);

        $remember = (bool) ($credentials['remember'] ?? false);

        if (! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_external' => false,
        ], $remember)) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        $request->session()->regenerate();

        $user = $request->user();
        TeamActivity::record($user, 'user.login', User::class, $user->id, ['channel' => 'web']);

        return response()->json([
            'user' => $this->userPayload($user),
        ]);
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    public function destroy(Request $request): JsonResponse
    {
        $user = $request->user();
        TeamActivity::record($user, 'user.logout', User::class, $user->id, ['channel' => 'web']);
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json(['message' => 'ok']);
    }

    private function userPayload($user): array
    {
        return [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'role_id' => $user->role_id ?? null,
            'roles' => $user->projectRoleNames(),
            'capabilities' => PanelAccess::capabilities($user),
            'organization_id' => $user->organization_id ?? null,
            'avatar' => $user->avatar ? '/storage/'.ltrim($user->avatar, '/') : null,
            'unread_notifications' => $user->userAlerts()->wherePivotNull('read_at')->count(),
            'agreements_required' => ! $user->hasProjectRole('super_admin') && $user->hasAnyProjectRole(['Organization', 'Secretary', 'Student']) && app(Agreements::class)->pending($user)->isNotEmpty(),
        ];
    }
}
