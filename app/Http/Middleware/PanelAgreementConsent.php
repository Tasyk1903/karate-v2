<?php

namespace App\Http\Middleware;

use App\Services\Account\Agreements;
use Closure;
use Illuminate\Http\Request;

final class PanelAgreementConsent
{
    public function handle(Request $request, Closure $next)
    {
        if ($request->user()->hasAnyProjectRole(['Organization', 'Secretary', 'Student'])
            && ! $request->is('api/panel/account/agreements', 'api/panel/account/agreements/*')
            && app(Agreements::class)->pending($request->user())->isNotEmpty()) {
            return response()->json(['code' => 'agreements_required', 'redirect' => '/panel/documents'], 409);
        }

        return $next($request);
    }
}
