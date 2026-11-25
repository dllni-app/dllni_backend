<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\Worker;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Cleaning\Http\Middleware\RejectUnapprovedV3WorkerLifecycle;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingWorkerScheduleService;
use Modules\Cleaning\Services\CleaningBookingWorkerSessionVisibilityService;
use Modules\Cleaning\Services\CleaningV3OperationalAdmissionService;
use Modules\Cleaning\Services\CleaningWorkerCustomerDataDisclosurePolicy;

/** V3-only feed. No parent-level legacy financial or address payload. */
final class CleaningV3WorkerVisitsController
{
    public function __invoke(Request $request): JsonResponse
    {
        $worker = $request->user()?->worker;
        abort_unless($worker instanceof Worker && $request->user()->is_active, 404);
        abort_unless($request->header(RejectUnapprovedV3WorkerLifecycle::HEADER) === RejectUnapprovedV3WorkerLifecycle::CAPABILITY, 426);
        $validated = $request->validate(['afterId' => ['sometimes', 'integer', 'min:0']]);
        $cursor = (int) ($validated['afterId'] ?? 0);
        $visibility = app(CleaningBookingWorkerSessionVisibilityService::class);
        $items = [];
        // Bounded keyset scan: never paginate unfiltered private booking rows.
        $bookings = CleaningBooking::query()->where('contract_version', 'v3')
            ->where('property_details->v3_admission_hold', false)
            ->whereNotNull('property_details->v3_operational_admitted_at')
            ->where('id', '>', $cursor)->whereNotIn('status', ['completed', 'cancelled'])
            ->whereHas('sessions', fn ($q) => $q->whereIn('status', ['scheduled', 'worker_assigned'])
                ->orWhereHas('workerAssignments', fn ($a) => $a->where('worker_id', $worker->id)->whereNull('released_at')))
            ->orderBy('id')->limit(50)->get();
        foreach ($bookings as $booking) {
            if (! CleaningV3OperationalAdmissionService::admitted($booking)) {
                continue;
            }
            $sessions = $visibility->visibleSessions($booking, $worker);
            if (! CleaningV3OperationalAdmissionService::enabled()) {
                $sessions = $sessions->filter(fn ($s) => $s->workerAssignments()->where('worker_id', $worker->id)->whereNull('released_at')->exists());
            }
            if ($sessions->isEmpty()) {
                continue;
            }
            $schedule = app(CleaningBookingWorkerScheduleService::class)->present($booking, $worker);
            // Whitelist the feed, never publish the complete schedule or other workers' earnings.
            $allowed = $sessions->pluck('id')->map(fn ($id) => (int) $id)->all();
            $visits = collect($schedule['sessions'])->filter(fn ($v) => in_array((int) ($v['sessionId'] ?? $v['id'] ?? 0), $allowed, true))
                ->map(fn ($v) => array_intersect_key($v, array_flip(['sessionId', 'id', 'scheduledDate', 'scheduledTime',
                    'durationHours', 'status', 'requiredWorkers', 'remainingWorkers', 'canAccept', 'pricingPreview'])))
                ->values()->all();
            $items[] = ['bookingId' => (int) $booking->id, 'bookingNumber' => $booking->booking_number,
                'locationSummary' => app(CleaningWorkerCustomerDataDisclosurePolicy::class)->neighborhoodLabel($booking),
                'contractVersion' => 3, 'visits' => $visits];
        }

        return response()->json(['success' => true, 'data' => $items,
            'nextAfterId' => $bookings->count() === 50 ? (int) $bookings->last()->id : null])
            ->header('Cache-Control', 'private, no-store');
    }
}
