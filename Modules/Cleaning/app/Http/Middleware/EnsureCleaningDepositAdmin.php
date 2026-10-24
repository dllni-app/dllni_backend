<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsureCleaningDepositAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $authenticatedUser = $request->user();
        $user = $authenticatedUser === null
            ? null
            : User::query()->find($authenticatedUser->getAuthIdentifier());

        if ($user === null || ! $user->is_active || ! $user->hasAnyRole(['admin', 'Super Admin'])) {
            return response()->json(['message' => 'Forbidden.'], Response::HTTP_FORBIDDEN);
        }

        return $next($request);
    }
}
