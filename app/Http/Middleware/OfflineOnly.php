<?php

namespace App\Http\Middleware;

use App\Models\MobileAccessToken;
use Closure;
use Illuminate\Http\Request;

final class OfflineOnly
{
    public function handle(Request $request, Closure $next): mixed
    {
        abort_unless($request->user()?->hasAnyProjectRole(['Organization', 'Secretary']), 403);
        abort_unless(MobileAccessToken::where('user_id', $request->user()->id)->where('token', hash('sha256', $request->bearerToken() ?? ''))->where('name', 'offline-v1')->exists(), 403);

        return $next($request)->header('Cache-Control', 'no-store');
    }
}
