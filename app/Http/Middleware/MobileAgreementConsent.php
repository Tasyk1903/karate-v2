<?php

namespace App\Http\Middleware;

use App\Services\Account\MobileAgreements;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class MobileAgreementConsent
{
    public function handle(Request $request, Closure $next): Response
    {
        if (app(MobileAgreements::class)->required($request->user())) {
            return response()->json(['code' => 'agreements_required', 'message' => __('mobile_profile.agreements_required')], 428);
        }

        return $next($request);
    }
}
