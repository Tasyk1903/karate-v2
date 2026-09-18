<?php

namespace App\Http\Middleware;

use App\Services\Account\MobileAppAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MobileRoles
{
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        abort_unless(in_array(app(MobileAppAccess::class)->role($request->user()), $roles, true), 403);

        return $next($request);
    }
}
