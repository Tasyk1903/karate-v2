<?php

namespace App\Http\Middleware;

use App\Services\PanelAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class ExaminationAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user() && PanelAccess::canViewExaminations($request->user()), 403);

        return $next($request);
    }
}
