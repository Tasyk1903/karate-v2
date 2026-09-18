<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class SuperAdmin
{
    public function handle(Request $request, Closure $next)
    {
        abort_unless($request->user()?->hasProjectRole('super_admin') && ! $request->user()->is_external, 403);

        return $next($request);
    }
}
