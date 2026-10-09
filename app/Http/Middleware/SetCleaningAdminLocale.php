<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Number;
use Symfony\Component\HttpFoundation\Response;

final class SetCleaningAdminLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->query('lang');

        if (in_array($locale, ['ar', 'en'], true)) {
            $request->session()->put('cleaning_admin_locale', $locale);
        }

        $currentLocale = (string) $request->session()->get('cleaning_admin_locale', 'ar');
        App::setLocale(in_array($currentLocale, ['ar', 'en'], true) ? $currentLocale : 'ar');
        // Keep dashboard labels Arabic, but format numeric values using Latin digits.
        Number::useLocale('en');

        return $next($request);
    }
}
