<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningBooking;
use Symfony\Component\HttpFoundation\Response;

/** Blocks legacy worker lifecycle mutations for supervisor-managed bookings. */
final class RejectSupervisedWorkerLifecycle
{
    public function handle(Request $request, Closure $next): Response
    {
        $parameter = $request->route('cleaning_booking');
        $booking = $parameter instanceof CleaningBooking
            ? $parameter
            : (is_numeric($parameter) ? CleaningBooking::find((int) $parameter) : null);

        if ($booking?->isSupervisedTeam()) {
            return response()->json([
                'code' => 'SUPERVISED_TEAM_ACTION_FORBIDDEN',
                'message' => 'هذا الطلب تديره جهة الإشراف، ولا يمكنك تنفيذ هذا الإجراء.',
            ], 403);
        }

        if ($booking !== null
            && (string) $booking->booking_kind === 'standard'
            && (int) $booking->number_of_workers > 1
            && $booking->supervisorAssignments()->where('active_slot', 1)->whereNull('released_at')->exists()
            && $this->isExecutionMutation($request)) {
            return response()->json([
                'code' => 'SUPERVISOR_ACTION_REQUIRED',
                'message' => 'بدء العمل وإنهاؤه والتحقق من صلاحيات المشرف المعيّن لهذا الحجز.',
            ], 403);
        }

        return $next($request);
    }

    private function isExecutionMutation(Request $request): bool
    {
        $path = '/'.$request->path();
        if ($request->isMethod('GET')) {
            return preg_match(
                '#/cleaning-bookings/[^/]+/security-code$#',
                $path,
            ) === 1;
        }

        if (! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return false;
        }

        return preg_match('#/cleaning-bookings/[^/]+/(security-code|start-work|complete|finish)$#', $path) === 1;
    }
}
