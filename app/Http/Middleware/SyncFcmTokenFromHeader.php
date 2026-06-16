<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class SyncFcmTokenFromHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $token = $this->resolveToken($request);
        if ($token !== null) {
            $user = $request->user();
            if (! $user instanceof User) {
                $user = Auth::guard('sanctum')->user();
            }

            if ($user instanceof User && ($user->getAttributes()['fcm_token'] ?? null) !== $token) {
                $user->forceFill(['fcm_token' => $token])->saveQuietly();
            }
        }

        return $next($request);
    }

    private function resolveToken(Request $request): ?string
    {
        foreach (['fcm-token', 'x-fcm-token', 'fcm_token'] as $key) {
            $normalized = $this->normalizeToken($request->header($key));
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function normalizeToken(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value !== '' ? $value : null;
    }
}
