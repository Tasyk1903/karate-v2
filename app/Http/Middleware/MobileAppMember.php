<?php

namespace App\Http\Middleware;

use App\Services\Account\MobileAppAccess;
use App\Services\Students\StudentProfileSetup;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MobileAppMember
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() && app(MobileAppAccess::class)->role($request->user()), 401);
        $user = $request->user();
        if (app(StudentProfileSetup::class)->required($user) && ! $request->is(
            'api/mobile/auth/user', 'api/mobile/agreements', 'api/mobile/agreements/*',
            'api/mobile/students/'.$user->id, 'api/mobile/files/users/'.$user->id.'/*',
            'api/mobile/account/delete',
        )) {
            return response()->json(['message' => __('student_account.profile_setup_required'),
                'code' => 'profile_setup_required'], 409)->header('X-Profile-Setup-Required', '1');
        }

        return $next($request);
    }
}
