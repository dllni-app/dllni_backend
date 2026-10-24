<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\SupervisorScheduledV2Service;
use Symfony\Component\HttpFoundation\Response;

/** Keep the single-booking supervisor workflow from mutating scheduled V2 visits. */
final class RejectUnsupportedV2SupervisorExecution
{
    public function __construct(private readonly SupervisorScheduledV2Service $scheduled) {}

    public function handle(Request $request, Closure $next): Response
    {
        // Inventory custody has its own authorized routes and must stay usable.
        // Only the legacy *execution* mutations assume a booking-wide state machine.
        if (! preg_match(
            '#^api/v1/supervisor/bookings/([0-9]+)/(verification-requests|verification/confirm|start|extension-requests|handover-requests|reports|media|attendance-incidents|replacement-requests)(?:/|$)#',
            $request->path(),
            $matches,
        ) || ! in_array($request->method(), ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
            return $next($request);
        }

        $user = $request->user();
        if ($user !== null && (
            $this->scheduled->assignedQuery($user)->whereKey((int) $matches[1])->exists()
            || CleaningBooking::query()
                ->whereKey((int) $matches[1])
                ->whereHas('supervisorAssignments', fn ($query) => $query
                    ->where('supervisor_user_id', $user->id)
                    ->where('active_slot', 1)
                    ->whereNull('released_at'))
                ->where('booking_kind', 'standard')
                ->where('number_of_workers', '>', 1)
                ->where(function ($query): void {
                    $query->whereHas('sessions')
                        ->orWhereHas('roomSchedules')
                        ->orWhereHas('roomPeriods');
                })
                ->exists()
        )) {
            return response()->json([
                'code' => 'SUPERVISOR_V2_EXECUTION_UNAVAILABLE',
                'message' => 'لا يمكن تنفيذ إجراءات الحجز الكلية على زيارات V2. استخدم مسار الجلسات المعتمد بعد تفعيله.',
            ], 422);
        }

        return $next($request);
    }
}
