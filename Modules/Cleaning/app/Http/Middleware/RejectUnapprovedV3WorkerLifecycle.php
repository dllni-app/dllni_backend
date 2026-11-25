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

        $admitted = \Modules\Cleaning\Services\CleaningV3OperationalAdmissionService::admitted($booking);
        if ($admitted && str_contains($request->path(), '/sessions/')) {
            $accepting = in_array($request->route()?->getName(), [
                'cleaning-bookings.sessions.accept-all', 'cleaning-bookings.sessions.accept-selected',
            ], true);
            if (! $accepting || \Modules\Cleaning\Services\CleaningV3OperationalAdmissionService::enabled()) {
                return $next($request);
            }
        }

        // A claimed capability is not proof of funding, genuine APK signing,
        // approved pricing, worker entitlement, or a valid quote. Never let
        // this header alone authorize any V3 mutation.
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
