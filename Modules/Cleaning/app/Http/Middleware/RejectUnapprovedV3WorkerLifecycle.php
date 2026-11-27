<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Middleware;

use App\Models\Worker;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningBooking;

/**
 * Fail-closed bridge until the independently funded V3 admission contract
 * and signed private worker APK capability rollout are verified.
 *
 * This middleware is installed ONLY on worker-facing acceptance/execution
 * endpoints. All existing V2 mutations pass through unchanged.
 */
final class RejectUnapprovedV3WorkerLifecycle
{
    public const HEADER = 'X-Cleaning-V3-Capabilities';

    public const CAPABILITY = 'visit-v3-r1';

    public function handle(Request $request, Closure $next): mixed
    {
        $param = $request->route('cleaning_booking');
        $booking = $param instanceof CleaningBooking
            ? $param
            : (is_numeric($param)
                ? CleaningBooking::query()->findOrFail((int) $param)
                : null);

        if (! $booking instanceof CleaningBooking
            || (string) ($booking->contract_version ?? '') !== 'v3') {
            return $next($request);
        }

        // Authentication happens before this middleware. Reject a non-worker
        // without disclosing any version or financial-gate information.
        if (! $request->user()?->worker instanceof Worker) {
            abort(404);
        }

        $capable = hash_equals(
            self::CAPABILITY,
            (string) $request->header(self::HEADER, ''),
        );

        if (! $capable) {
            return $this->deny(
                'UPDATE_REQUIRED',
                'Please update the worker app before handling V3 visits.',
                426,
            );
        }

        // Parent-level legacy routes must never execute a V3 visit.
        $route = $request->route()?->getName();
        if (is_string($route) && str_starts_with($route, 'api.')) {
            $route = substr($route, 4);
        }
        $allowed = [
            'cleaning-bookings.sessions.accept-all',
            'cleaning-bookings.sessions.accept-selected',
            'cleaning-bookings.sessions.start-travel',
            'cleaning-bookings.sessions.location',
            'cleaning-bookings.sessions.arrive',
            'cleaning-bookings.sessions.security-code',
            'cleaning-bookings.sessions.start-work',
            'cleaning-bookings.sessions.complete',
        ];
        $visit = $request->route('cleaning_booking_session');
        $policy = app(\Modules\Cleaning\Services\CleaningV3OperationalAdmissionPolicy::class);
        if ($visit instanceof \Modules\Cleaning\Models\CleaningBookingSession
            && $visit->supervision_mode === \Modules\Cleaning\Models\CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
            && in_array($route, ['cleaning-bookings.sessions.security-code', 'cleaning-bookings.sessions.start-work', 'cleaning-bookings.sessions.complete'], true)) {
            return $this->deny('V3_SUPERVISOR_EXECUTION_REQUIRED',
                'Use the verified supervisor visit workflow.', 409);
        }
        if (in_array($route, $allowed, true) && $policy->approved($booking)
            && (in_array($route, ['cleaning-bookings.sessions.accept-all', 'cleaning-bookings.sessions.accept-selected'], true)
                || ($visit instanceof \Modules\Cleaning\Models\CleaningBookingSession && $policy->approved($booking, $visit)))) {
            return $next($request);
        }
        // Capability is a compatibility hint, never authorization.
        return $this->deny(
            'V3_FINANCIAL_APPROVAL_REQUIRED',
            'V3 work acceptance is unavailable until its financial and signing gates are verified.',
            503,
        );
    }

    private function deny(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'requiredCapabilities' => [self::CAPABILITY],
            'retryable' => false,
        ], $status, ['Cache-Control' => 'private, no-store']);
    }
}
