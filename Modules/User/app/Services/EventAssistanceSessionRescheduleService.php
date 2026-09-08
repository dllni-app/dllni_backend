<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Services\CleaningBookingSessionCapabilityService;
use Modules\Cleaning\Services\CleaningBookingSessionFinancialAggregationService;
use Modules\Cleaning\Services\WorkerBookingScheduleConflictService;

final class EventAssistanceSessionRescheduleService
{
    public function __construct(
        private readonly EventAssistanceScheduleService $eventSchedule,
        private readonly WorkerBookingScheduleConflictService $scheduleConflicts,
        private readonly CleaningBookingSessionFinancialAggregationService $financialAggregation,
        private readonly CleaningBookingSessionCapabilityService $capabilities,
    ) {}

    /**
     * Change one future event-assistance execution session without rebuilding or
     * mutating sibling sessions. Accepted workers may keep their assignment when
     * only the date/time changes, provided their resulting schedule remains free.
     * Duration changes stay editable until a worker has accepted that session.
     *
     * @param  array{date:string,time:string,hours?:float|int|string}  $input
     */
    public function reschedule(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        int $customerId,
        array $input,
    ): CleaningBookingSession {
        if ((int) $booking->customer_id !== $customerId) {
            abort(403, 'Booking belongs to another customer.');
        }

        $updated = DB::transaction(function () use ($booking, $session, $customerId, $input): CleaningBookingSession {
            $lockedBooking = CleaningBooking::query()
                ->whereKey($booking->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ((int) $lockedBooking->customer_id !== $customerId) {
                abort(403, 'Booking belongs to another customer.');
            }

            if (! $lockedBooking->isEventAssistanceBooking()) {
                throw ValidationException::withMessages([
                    'session' => ['Only event-assistance execution days can be rescheduled here.'],
                ]);
            }

            $lockedSession = CleaningBookingSession::query()
                ->whereKey($session->id)
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->with('workerAssignments.worker')
                ->lockForUpdate()
                ->first();

            if (! $lockedSession instanceof CleaningBookingSession) {
                throw ValidationException::withMessages([
                    'session' => ['The selected session does not belong to this booking.'],
                ]);
            }

            $now = CarbonImmutable::now(config('app.timezone'));
            if (! $this->capabilities->canCustomerRescheduleEventSession($lockedBooking, $lockedSession, $now)) {
                throw ValidationException::withMessages([
                    'session' => ['Only a future event day that has not entered travel or execution can be rescheduled.'],
                ]);
            }

            $date = CarbonImmutable::parse((string) $input['date'], config('app.timezone'))->toDateString();
            $time = mb_trim((string) $input['time']);
            $hours = array_key_exists('hours', $input)
                ? $this->normalizeHours((float) $input['hours'])
                : (float) $lockedSession->duration_hours;

            $newStart = CarbonImmutable::parse("{$date} {$time}", config('app.timezone'));
            if (! $newStart->gt($now)) {
                throw ValidationException::withMessages([
                    'time' => ['The selected event day must be scheduled in the future.'],
                ]);
            }

            if (CleaningBookingSession::query()
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->where('id', '!=', $lockedSession->id)
                ->where('status', '!=', CleaningBookingSessionStatus::Superseded->value)
                ->whereDate('scheduled_date', $date)
                ->where('scheduled_time', $time)
                ->exists()) {
                throw ValidationException::withMessages([
                    'time' => ['Another event session already uses the selected date and time.'],
                ]);
            }

            $activeAssignments = $lockedSession->workerAssignments
                ->filter(static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => in_array(
                    (string) ($assignment->status?->value ?? $assignment->status),
                    CleaningBookingWorkerAssignmentStatus::activeValues(),
                    true,
                ))
                ->values();

            $durationChanged = abs($hours - (float) $lockedSession->duration_hours) > 0.0001;
            if (
                $durationChanged
                && ! $this->capabilities->canCustomerChangeEventSessionDuration($lockedBooking, $lockedSession, $now)
            ) {
                throw ValidationException::withMessages([
                    'hours' => ['Session duration cannot be changed after a worker has accepted this event day.'],
                ]);
            }

            // Mutate only the in-memory candidate first. Conflict detection excludes
            // this session by id, so sibling sessions and other bookings still count.
            $lockedSession->forceFill([
                'scheduled_date' => $date,
                'scheduled_time' => $time,
                'duration_hours' => $hours,
            ]);

            foreach ($activeAssignments as $assignment) {
                $worker = $assignment->worker;
                if ($worker === null) {
                    continue;
                }

                $this->scheduleConflicts->forgetWorker($worker);
                if ($this->scheduleConflicts->hasConflictForSession($worker, $lockedSession)) {
                    throw ValidationException::withMessages([
                        'time' => ["The new schedule conflicts with another booking accepted by worker #{$worker->id}."],
                    ]);
                }
            }

            if ($activeAssignments->isEmpty()) {
                $this->repriceUnassignedSession($lockedBooking, $lockedSession, $date, $time, $hours);
            }

            $lockedSession->forceFill([
                'version' => max(0, (int) $lockedSession->version) + 1,
            ])->save();

            $this->financialAggregation->sync($lockedBooking);
            $this->syncParentScheduleMetadata($lockedBooking);

            return $lockedSession->fresh(['workerAssignments.worker.user']) ?? $lockedSession;
        }, 3);

        $freshBooking = $booking->fresh();
        if ($freshBooking instanceof CleaningBooking) {
            CleaningBookingTrackingUpdated::dispatch(
                (int) $freshBooking->id,
                [
                    'action' => 'event_session_rescheduled',
                    'sessionId' => (int) $updated->id,
                    'scheduledDate' => $updated->scheduled_date?->toDateString(),
                    'scheduledTime' => (string) $updated->scheduled_time,
                    'durationHours' => (float) $updated->duration_hours,
                    'occurredAt' => now()->toIso8601String(),
                ],
            );
        }

        return $updated;
    }

    private function repriceUnassignedSession(
        CleaningBooking $booking,
        CleaningBookingSession $session,
        string $date,
        string $time,
        float $hours,
    ): void {
        $plan = [
            'mode' => 'single_day',
            'sessions' => [[
                'sequence' => (int) $session->sequence,
                'date' => $date,
                'time' => $time,
                'hours' => $hours,
            ]],
            'daysCount' => 1,
            'totalHours' => $hours,
        ];

        $pricing = $this->eventSchedule->quote(
            plan: $plan,
            propertyType: (string) $booking->property_type,
            propertyDetails: (array) ($booking->property_details ?? []),
            addressLatitude: $booking->address_latitude,
            addressLongitude: $booking->address_longitude,
            preferredWorkerId: $booking->resolvedAssignmentMode() === 'preferred_worker'
                ? $booking->preferred_worker_id
                : null,
            requiredWorkers: max(1, (int) ($session->required_workers ?? $booking->number_of_workers ?? 1)),
        );
        $sessionPricing = is_array($pricing['schedule']['sessions'][0] ?? null)
            ? $pricing['schedule']['sessions'][0]
            : [];
        $snapshot = is_array($session->pricing_snapshot) ? $session->pricing_snapshot : [];

        $session->forceFill([
            'base_price' => (float) ($sessionPricing['basePrice'] ?? 0),
            'addons_total' => 0,
            'travel_fee' => (float) ($sessionPricing['travelFee'] ?? 0),
            'travel_distance_km' => isset($pricing['distanceKm']) ? (float) $pricing['distanceKm'] : null,
            'admin_margin_amount' => (float) ($sessionPricing['adminMargin'] ?? 0),
            'total_price' => (float) ($sessionPricing['totalPrice'] ?? 0),
            'is_pricing_final' => (bool) ($pricing['isPricingFinal'] ?? false),
            'pricing_snapshot' => [
                ...$snapshot,
                'eventHourlyRate' => (float) ($pricing['eventHourlyRate'] ?? ($snapshot['eventHourlyRate'] ?? 0)),
                'requiredWorkers' => max(1, (int) ($session->required_workers ?? 1)),
                'currency' => (string) ($pricing['currency'] ?? config('app.currency', 'SYP')),
                'rescheduledAt' => now()->toIso8601String(),
            ],
        ]);
    }

    private function syncParentScheduleMetadata(CleaningBooking $booking): void
    {
        $sessions = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('status', '!=', CleaningBookingSessionStatus::Superseded->value)
            ->orderBy('scheduled_date')
            ->orderBy('scheduled_time')
            ->get();
        $first = $sessions->first();
        $lockedBooking = CleaningBooking::query()
            ->whereKey($booking->id)
            ->lockForUpdate()
            ->firstOrFail();
        $details = (array) ($lockedBooking->property_details ?? []);
        $details['hours'] = (float) $lockedBooking->total_hours;

        $lockedBooking->forceFill([
            'property_details' => $details,
            'estimated_hours' => (float) $lockedBooking->total_hours,
            'scheduled_date' => $first?->scheduled_date,
            'scheduled_time' => $first?->scheduled_time,
        ])->saveQuietly();
    }

    private function normalizeHours(float $hours): float
    {
        return ceil(max(1.0, min(24.0, $hours)) * 2) / 2;
    }
}
