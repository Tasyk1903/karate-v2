<?php

namespace App\Http\Controllers\Mobile;

use App\Http\Controllers\Controller;
use App\Models\MobileAccessToken;
use App\Models\User;
use App\Services\Account\MobileAppAccess;
use App\Services\Account\MobileSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MobileAuthController extends Controller
{
    public function login(Request $request): JsonResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
            'locale' => ['sometimes', 'in:ru,en'],
        ]);

        App::setLocale($credentials['locale'] ?? 'ru');

        if (! Auth::attempt([
            'email' => $credentials['email'],
            'password' => $credentials['password'],
            'is_external' => false,
        ])) {
            throw ValidationException::withMessages([
                'email' => __('auth.failed'),
            ]);
        }

        /** @var User $user */
        $user = Auth::user();

        if (! app(MobileAppAccess::class)->role($user)) {
            Auth::logout();

            throw ValidationException::withMessages([
                'email' => __('auth.mobile_member_only'),
            ]);
        }

        return response()->json(app(MobileSession::class)->issue($user, (bool) ($credentials['remember'] ?? true)))
            ->header('Cache-Control', 'no-store');
    }

    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'user' => app(MobileSession::class)->user($request->user()),
        ]);
    }

    public function logout(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $plainToken = $request->bearerToken();

        DB::transaction(function () use ($plainToken, $user): void {
            $deleted = MobileAccessToken::query()
                ->where('user_id', $user->id)
                ->where('token', hash('sha256', $plainToken ?? ''))
                ->delete();

            if ($deleted) {
                $this->writeActivityLog($user, 'Выход из мобильного приложения', 'mobile.auth.logout', User::class, $user->id);
            }
        });

        return response()->json(['message' => 'ok']);
    }

    private function writeActivityLog(User $causer, string $description, string $event, ?string $subjectType = null, int|string|null $subjectId = null, array $properties = []): void
    {
        DB::table('activity_log')->insert([
            'log_name' => 'mobile',
            'description' => $description,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'event' => $event,
            'causer_type' => User::class,
            'causer_id' => $causer->id,
            'properties' => json_encode($properties, JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
