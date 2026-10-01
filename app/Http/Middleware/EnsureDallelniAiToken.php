<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureDallelniAiToken
{
    public function handle(Request $request, Closure $next): Response
    {
        $expected = (string) config('services.dallelni_search.auth_token', '');
        $provided = (string) $request->header('auth-token', '');

        if ($expected === '' || $provided === '' || ! hash_equals($expected, $provided)) {
            abort(Response::HTTP_UNAUTHORIZED, 'Unauthorized.');
        }

        return $next($request);
    }
}
