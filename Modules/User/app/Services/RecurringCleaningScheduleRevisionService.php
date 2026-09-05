<?php

declare(strict_types=1);

namespace Modules\User\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Services\CleaningBookingSessionFinancialAggregationService;
use Modules\Cleaning\Services\CleaningBookingSessionParentStateService;
use Modules\Cleaning\Services\CleaningLifecycleNotificationService;
use Throwable;

final class RecurringCleaningScheduleRevisionService
{
    public function __construct(
        private readonly UserCleaningOrderEstimationService $estimationService,
        private readonly CleaningBookingSessionFinancialAggregationService $financialAggregation,
        private readonly CleaningBookingSessionParentStateService $parentState,
        private readonly CleaningLifecycleNotificationService $notifications,
    ) {}

    /** @param array<string,mixed> $schedule */
    public function preview(CleaningBooking $booking, int $customerId, array $schedule): array
    {
        $this->assertCustomer($booking, $customerId);
        $built = $this->build($booking, $schedule, false);

        return $built['preview'];
    }

    /** @param array<string,mixed> $schedule */
    public function confirm(CleaningBooking $booking, int $customerId, array $schedule, string $revisionToken): array
    {
        $this->assertCustomer($booking, $customerId);
        $releasedWorkerIds = [];
        $supersededSessionIds = [];
        $createdSessionIds = [];
        $confirmedPreview = [];

        DB::transaction(function () use (
            $booking,
            $schedule,
            $revisionToken,
            &$releasedWorkerIds,
            &$supersededSessionIds,
            &$createdSessionIds,
            &$confirmedPreview,
        ): void {
            $lockedBooking = CleaningBooking::query()->whereKey($booking->id)->lockForUpdate()->firstOrFail();
            $built = $this->build($lockedBooking, $schedule, true);
            $confirmedPreview = $built['preview'];

            if (! hash_equals((string) $confirmedPreview['revisionToken'], mb_trim($revisionToken))) {
                throw ValidationException::withMessages([
                    'revisionToken' => ['تغيّرت حالة الحجز أو الأسعار. أعد معاينة التعديل قبل التأكيد.'],
                ]);
            }

            /** @var Collection<int,CleaningBookingSession> $editable */
            $editable = $built['editable'];
            $releasedAt = now();
            $maxSequence = max(0, (int) CleaningBookingSession::query()
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->max('sequence'));

            foreach ($editable->values() as $index => $session) {
                $assignments = CleaningBookingSessionWorkerAssignment::query()
                    ->where('cleaning_booking_session_id', $session->id)
                    ->whereIn('status', CleaningBookingWorkerAssignmentStatus::activeValues())
                    ->lockForUpdate()
                    ->get();

                foreach ($assignments as $assignment) {
                    $workerId = (int) $assignment->worker_id;
                    if ($workerId > 0) {
                        $releasedWorkerIds[] = $workerId;
                    }
                    $assignment->forceFill([
                        'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
                        'released_at' => $releasedAt,
                        'released_reason' => 'Customer confirmed a recurring schedule revision.',
                    ])->save();
                }

                $session->forceFill([
                    'sequence' => $maxSequence + 1000 + $index + 1,
                    'status' => CleaningBookingSessionStatus::Superseded,
                    'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
                    'cancellation_fee' => 0,
                    'version' => max(1, (int) $session->version) + 1,
                ])->save();
                $supersededSessionIds[] = (int) $session->id;
            }

            foreach ($built['proposedSessions'] as $index => $session) {
                $created = CleaningBookingSession::query()->create([
                    'cleaning_booking_id' => $lockedBooking->id,
                    'sequence' => $maxSequence + 2000 + $index + 1,
                    'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
                    'calculation_mode' => $built['calculationMode'],
                    'scheduled_date' => $session['date'],
                    'scheduled_time' => $session['time'],
                    'duration_hours' => $built['sessionHours'],
                    'required_workers' => max(1, (int) $lockedBooking->number_of_workers),
                    'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
                    'status' => CleaningBookingSessionStatus::Scheduled,
                    'base_price' => $built['singleVisitPricing']['basePrice'],
                    'addons_total' => $built['singleVisitPricing']['addonsTotal'],
                    'materials_total' => 0,
                    'special_services_total' => 0,
                    'travel_fee' => $built['singleVisitPricing']['travelFee'],
                    'travel_distance_km' => $built['singleVisitPricing']['distanceKm'],
                    'admin_margin_amount' => $built['singleVisitPricing']['adminMargin'],
                    'extension_fee_total' => 0,
                    'cancellation_fee' => 0,
                    'total_price' => $built['singleVisitPricing']['totalPrice'],
                    'is_pricing_final' => $built['singleVisitPricing']['isPricingFinal'],
                    'pricing_snapshot' => [
                        'scheduleType' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
                        'scheduleMode' => 'multi_day',
                        'revisedAt' => now()->toIso8601String(),
                        'revisionToken' => $confirmedPreview['revisionToken'],
                        'pricingAlgorithmVersion' => $this->estimationService->algorithmVersion(),
                        'calculationMode' => $built['calculationMode'],
                        'hoursPerVisit' => $built['calculationMode'] === RecurringCleaningScheduleService::CALCULATION_HOURS ? $built['sessionHours'] : null,
                        'perVisitEstimatedHours' => $built['sessionHours'],
                        'derivedHourlyRatePerWorker' => $built['singleVisitPricing']['recurringHourlyRatePerWorker'] ?? null,
                        'requiredWorkers' => max(1, (int) $lockedBooking->number_of_workers),
                        'currency' => (string) ($built['singleVisitPricing']['currency'] ?? config('app.currency', 'SYP')),
                    ],
                    'version' => 1,
                ]);
                $createdSessionIds[] = (int) $created->id;
            }

            $this->resequenceVisibleSessions($lockedBooking);
            $this->financialAggregation->sync($lockedBooking);

            $visible = CleaningBookingSession::query()
                ->where('cleaning_booking_id', $lockedBooking->id)
                ->where('status', '!=', CleaningBookingSessionStatus::Superseded->value)
                ->orderBy('scheduled_date')
                ->orderBy('scheduled_time')
                ->orderBy('id')
                ->get();
            $first = $visible->first();
            $chargeable = $visible->reject(fn (CleaningBookingSession $session): bool => in_array(
                $this->status($session),
                [CleaningBookingSessionStatus::Cancelled->value, CleaningBookingSessionStatus::Skipped->value],
                true,
            ));

            $lockedBooking->refresh();
            $lockedBooking->forceFill([
                'scheduled_date' => $first?->scheduled_date,
                'scheduled_time' => $first?->scheduled_time,
                'estimated_hours' => round((float) $chargeable->sum('duration_hours'), 2),
                'is_pricing_final' => (bool) $built['singleVisitPricing']['isPricingFinal'],
            ])->saveQuietly();
        }, 3);

        $this->parentState->refresh($booking);
        $fresh = $booking->fresh(['customer']) ?? $booking;
        $releasedWorkerIds = array_values(array_unique($releasedWorkerIds));

        foreach ($releasedWorkerIds as $workerId) {
            $this->notifications->notifyWorkerById(
                booking: $fresh,
                workerId: $workerId,
                canonicalType: 'cleaning.booking.updated',
                action: 'customer_revised_recurring_schedule',
                actorRole: 'customer',
                occurredAt: now()->toIso8601String(),
                extraData: [
                    'supersededSessionIds' => $supersededSessionIds,
                    'createdSessionIds' => $createdSessionIds,
                ],
            );
        }

        return [
            'booking' => $fresh,
            'revision' => [
                ...$confirmedPreview,
                'applied' => true,
                'supersededSessionIds' => $supersededSessionIds,
                'createdSessionIds' => $createdSessionIds,
                'releasedWorkerIds' => $releasedWorkerIds,
            ],
        ];
    }

    /** @param array<string,mixed> $schedule */
    private function build(CleaningBooking $booking, array $schedule, bool $lock): array
    {
        $this->assertRecurringAndEditable($booking);
        $proposedSessions = $this->normalizeProposedSessions($schedule);
        $sessions = $this->sessions($booking, $lock);
        $editable = $sessions->filter(fn (CleaningBookingSession $session): bool => $this->isEditableFutureSession($session))->values();

        if ($editable->isEmpty()) {
            throw ValidationException::withMessages([
                'schedule' => ['لا توجد زيارات دورية مستقبلية قابلة للتعديل.'],
            ]);
        }

        $visibleWithoutEditable = $sessions->reject(
            fn (CleaningBookingSession $session): bool => $editable->contains('id', $session->id),
        )->values();
        if ($visibleWithoutEditable->count() + count($proposedSessions) < 2) {
            throw ValidationException::withMessages([
                'schedule.sessions' => ['يجب أن يبقى الحجز الدوري مكوّناً من زيارتين على الأقل إجمالاً.'],
            ]);
        }

        $preferredWorkerId = $booking->resolvedAssignmentMode() === 'preferred_worker'
            ? $booking->preferred_worker_id
            : null;
        $rawCalculationMode = mb_strtolower((string) ($editable->first()->calculation_mode ?? ''));
        $calculationMode = $rawCalculationMode === RecurringCleaningScheduleService::CALCULATION_HOURS
            ? RecurringCleaningScheduleService::CALCULATION_HOURS
            : RecurringCleaningScheduleService::CALCULATION_TASK;
        $estimate = $this->estimationService->estimate(
            (string) $booking->property_type,
            (array) ($booking->property_details ?? []),
        );
        $sessionHours = $calculationMode === RecurringCleaningScheduleService::CALCULATION_HOURS
            ? round(max(1.0, (float) ($editable->first()->duration_hours ?? 1)), 2)
            : round(max(0.01, (float) ($estimate['estimatedHours'] ?? $editable->first()->duration_hours ?? 1)), 2);
        try {
            $singleVisitPricing = $calculationMode === RecurringCleaningScheduleService::CALCULATION_HOURS
                ? $this->estimationService->priceRecurringHours(
                    (string) $booking->property_type,
                    (array) ($booking->property_details ?? []),
                    $booking->address_latitude,
                    $booking->address_longitude,
                    $preferredWorkerId,
                    $sessionHours,
                    max(1, (int) $booking->number_of_workers),
                )
                : $this->estimationService->price(
                    (string) $booking->property_type,
                    (array) ($booking->property_details ?? []),
                    $booking->address_latitude,
                    $booking->address_longitude,
                    $preferredWorkerId,
                );
        } catch (Throwable $exception) {
            report($exception);
            throw ValidationException::withMessages([
                'schedule' => ['تعذر إعادة تسعير الزيارات المستقبلية. حدّث بيانات الحجز وحاول مرة أخرى.'],
            ]);
        }

        $immutableChargeableTotal = round((float) $visibleWithoutEditable
            ->reject(fn (CleaningBookingSession $session): bool => in_array(
                $this->status($session),
                [CleaningBookingSessionStatus::Cancelled->value, CleaningBookingSessionStatus::Skipped->value],
                true,
            ))
            ->sum('total_price'), 2);
        $cancellationFees = round((float) $visibleWithoutEditable
            ->filter(fn (CleaningBookingSession $session): bool => $this->status($session) === CleaningBookingSessionStatus::Cancelled->value)
            ->sum('cancellation_fee'), 2);
        $proposedVisitsTotal = round((float) $singleVisitPricing['totalPrice'] * count($proposedSessions), 2);
        $newGrossTotal = round($immutableChargeableTotal + $proposedVisitsTotal + $cancellationFees, 2);
        $discount = min($newGrossTotal, max(0.0, (float) ($booking->discount_amount ?? 0)));
        $newTotal = round($newGrossTotal - $discount, 2);
        $oldTotal = round((float) $booking->total_price, 2);
        $priceDelta = round($newTotal - $oldTotal, 2);
        $currentFuture = $editable->map(fn (CleaningBookingSession $session): array => [
            'date' => $session->scheduled_date?->toDateString(),
            'time' => (string) $session->scheduled_time,
        ])->all();
        $scheduleChanged = $currentFuture !== $proposedSessions;
        $tokenPayload = [
            'bookingId' => (int) $booking->id,
            'bookingUpdatedAt' => $booking->updated_at?->toIso8601String(),
            'state' => $sessions->map(fn (CleaningBookingSession $session): array => [
                'id' => (int) $session->id,
                'version' => (int) $session->version,
                'status' => $this->status($session),
                'date' => $session->scheduled_date?->toDateString(),
                'time' => (string) $session->scheduled_time,
                'totalPrice' => round((float) $session->total_price, 2),
            ])->all(),
            'proposedSessions' => $proposedSessions,
            'calculationMode' => $calculationMode,
            'sessionHours' => $sessionHours,
            'newTotal' => $newTotal,
            'singleVisitTotal' => round((float) $singleVisitPricing['totalPrice'], 2),
        ];
        $revisionToken = hash('sha256', json_encode($tokenPayload, JSON_THROW_ON_ERROR));

        $preview = [
            'revisionToken' => $revisionToken,
            'requiresReconfirmation' => $scheduleChanged,
            'scheduleChanged' => $scheduleChanged,
            'priceChanged' => abs($priceDelta) >= 0.01,
            'oldTotal' => $oldTotal,
            'newTotal' => $newTotal,
            'priceDelta' => $priceDelta,
            'discountAmount' => round($discount, 2),
            'currency' => (string) ($singleVisitPricing['currency'] ?? config('app.currency', 'SYP')),
            'editableSessionsCount' => $editable->count(),
            'preservedSessionsCount' => $visibleWithoutEditable->count(),
            'proposedSessionsCount' => count($proposedSessions),
            'sessionHours' => $sessionHours,
            'calculationMode' => $calculationMode,
            'hoursPerVisit' => $calculationMode === RecurringCleaningScheduleService::CALCULATION_HOURS ? $sessionHours : null,
            'singleVisitPricing' => [
                'basePrice' => round((float) $singleVisitPricing['basePrice'], 2),
                'addonsTotal' => round((float) $singleVisitPricing['addonsTotal'], 2),
                'travelFee' => round((float) $singleVisitPricing['travelFee'], 2),
                'adminMargin' => round((float) $singleVisitPricing['adminMargin'], 2),
                'totalPrice' => round((float) $singleVisitPricing['totalPrice'], 2),
                'isPricingFinal' => (bool) $singleVisitPricing['isPricingFinal'],
            ],
            'sessions' => array_map(fn (array $session): array => [
                ...$session,
                'hours' => $sessionHours,
                'totalPrice' => round((float) $singleVisitPricing['totalPrice'], 2),
            ], $proposedSessions),
        ];

        return [
            'preview' => $preview,
            'editable' => $editable,
            'proposedSessions' => $proposedSessions,
            'singleVisitPricing' => $singleVisitPricing,
            'sessionHours' => $sessionHours,
            'calculationMode' => $calculationMode,
        ];
    }

    /** @return Collection<int,CleaningBookingSession> */
    private function sessions(CleaningBooking $booking, bool $lock): Collection
    {
        $query = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('session_type', CleaningBookingSession::TYPE_RECURRING_CLEANING)
            ->where('status', '!=', CleaningBookingSessionStatus::Superseded->value)
            ->with('workerAssignments')
            ->orderBy('scheduled_date')
            ->orderBy('scheduled_time')
            ->orderBy('id');

        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get();
    }

    private function isEditableFutureSession(CleaningBookingSession $session): bool
    {
        if (! in_array($this->status($session), [
            CleaningBookingSessionStatus::Scheduled->value,
            CleaningBookingSessionStatus::WorkerAssigned->value,
        ], true)) {
            return false;
        }
        $startsAt = $session->startsAt();
        if ($startsAt === null || ! $startsAt->gt(CarbonImmutable::now(config('app.timezone')))) {
            return false;
        }
        if ($session->started_travel_at !== null || $session->work_started_at !== null) {
            return false;
        }

        return ! $session->workerAssignments->contains(
            static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->isActive()
                && $assignment->started_travel_at !== null,
        );
    }

    /** @param array<string,mixed> $schedule @return array<int,array{date:string,time:string}> */
    private function normalizeProposedSessions(array $schedule): array
    {
        $sessions = is_array($schedule['sessions'] ?? null) ? $schedule['sessions'] : [];
        $normalized = [];
        foreach ($sessions as $session) {
            if (! is_array($session)) {
                continue;
            }
            $normalized[] = [
                'date' => CarbonImmutable::parse((string) ($session['date'] ?? ''), config('app.timezone'))->toDateString(),
                'time' => mb_trim((string) ($session['time'] ?? '')),
            ];
        }
        usort($normalized, static fn (array $left, array $right): int => [$left['date'], $left['time']] <=> [$right['date'], $right['time']]);

        return $normalized;
    }

    private function resequenceVisibleSessions(CleaningBooking $booking): void
    {
        $visible = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('status', '!=', CleaningBookingSessionStatus::Superseded->value)
            ->orderBy('scheduled_date')
            ->orderBy('scheduled_time')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($visible->values() as $index => $session) {
            $session->forceFill(['sequence' => 2000000 + $index])->saveQuietly();
        }
        foreach ($visible->values() as $index => $session) {
            $session->forceFill(['sequence' => $index + 1])->saveQuietly();
        }
    }

    private function assertRecurringAndEditable(CleaningBooking $booking): void
    {
        if ($booking->recurring_paused_at !== null) {
            throw ValidationException::withMessages([
                'schedule' => ['استأنف الحجز الدوري قبل تعديل مواعيده المستقبلية.'],
            ]);
        }
        $hasRecurring = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('session_type', CleaningBookingSession::TYPE_RECURRING_CLEANING)
            ->where('status', '!=', CleaningBookingSessionStatus::Superseded->value)
            ->exists();
        if (! $hasRecurring) {
            throw ValidationException::withMessages([
                'schedule' => ['هذا الطلب ليس حجز تنظيف دوري.'],
            ]);
        }
    }

    private function assertCustomer(CleaningBooking $booking, int $customerId): void
    {
        if ((int) $booking->customer_id !== $customerId) {
            abort(403, 'Booking belongs to another customer.');
        }
    }

    private function status(CleaningBookingSession $session): string
    {
        return $session->status instanceof CleaningBookingSessionStatus
            ? $session->status->value
            : (string) $session->status;
    }
}
