<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class PanelLocale
{
    public function handle(Request $request, Closure $next)
    {
        $locale = $request->input('locale', $request->cookie('kr-locale', $request->getPreferredLanguage(['ru', 'en']) ?? 'ru'));
        app()->setLocale(in_array($locale, ['ru', 'en'], true) ? $locale : 'ru');

        return $next($request);
    }
}
