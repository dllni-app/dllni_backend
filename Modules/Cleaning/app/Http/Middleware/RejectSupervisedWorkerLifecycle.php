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

        return $next($request);
    }
}
