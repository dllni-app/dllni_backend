<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Models\CleaningBooking;
use Symfony\Component\HttpFoundation\Response;

final class RequireSessionScopedCleaningLifecycle
{
    public function handle(Request $request, Closure $next): Response
    {
        if (str_contains($request->path(), '/sessions/')) {
            return $next($request);
        }

        if (! $this->isLegacyLifecycleAction($request)) {
            return $next($request);
        }

        $booking = $this->resolveBooking($request);
        if (! $booking instanceof CleaningBooking || ! $booking->isEventAssistanceBooking()) {
            return $next($request);
        }

        if ($booking->sessions()->count() <= 1) {
            return $next($request);
        }

        throw ValidationException::withMessages([
            'session' => ['Multi-day event assistance requires a session-scoped lifecycle endpoint.'],
        ]);
    }

    private function isLegacyLifecycleAction(Request $request): bool
    {
        $path = $request->path();

        return preg_match('#api/v1/cleaning-bookings/[^/]+/(security-code|sos|start-travel|location|arrive|start-work|complete|finish|cancel)$#', $path) === 1
            || preg_match('#api/v1/user/cleaning/orders/[^/]+/(start-verification/confirm|completion/confirm|completion/reject|completion/extend-time)$#', $path) === 1;
    }

    private function resolveBooking(Request $request): ?CleaningBooking
    {
        foreach (['cleaning_booking', 'booking', 'order'] as $key) {
            $value = $request->route($key);
            if ($value instanceof CleaningBooking) {
                return $value;
            }
            if (is_numeric($value)) {
                $booking = CleaningBooking::query()->find((int) $value);
                if ($booking instanceof CleaningBooking) {
                    return $booking;
                }
            }
        }

        return null;
    }
}
