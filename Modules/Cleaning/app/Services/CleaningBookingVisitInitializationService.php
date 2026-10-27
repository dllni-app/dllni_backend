<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Support\CleaningRuntimeSettings;
use RuntimeException;

final class CleaningBookingVisitInitializationService
{
    /**
     * @param  array<string, mixed>  $quote
     */
    public function materializeCreatedRoomPeriodVisit(CleaningBooking $booking, array $quote): CleaningBookingSession
    {
        $booking->loadMissing('roomPeriods.room');
        $periods = $booking->roomPeriods;

        if ($periods->isEmpty()
            || $periods->contains(fn ($period): bool => (int) $period->visit_sequence !== 1)
            || $periods->contains(fn ($period): bool => $period->cleaning_booking_room_id === null
                || $period->room === null
                || (int) $period->room->cleaning_booking_id !== (int) $booking->id)
            || $booking->sessions()->exists()) {
            throw new RuntimeException('Room-period visit foundation invariants are incomplete.');
        }

        [$scheduledDate, $scheduledTime, $durationHours] = $this->roomPeriodEnvelope($periods);
        $requiredWorkers = max(1, (int) ($quote['requiredSimultaneousWorkers'] ?? $booking->number_of_workers ?? 1));
        $session = $booking->sessions()->create([
            'sequence' => 1,
            'session_type' => null,
            'calculation_mode' => 'task',
            'scheduled_date' => $scheduledDate,
            'scheduled_time' => $scheduledTime,
            'duration_hours' => $durationHours,
            'required_workers' => $requiredWorkers,
            'supervision_mode' => $this->supervisionMode($requiredWorkers),
            'supervision_initialized_at' => now(),
            'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
            'status' => CleaningBookingSessionStatus::Scheduled,
            'base_price' => (float) $booking->base_price,
            'addons_total' => (float) $booking->addons_total,
            'materials_total' => (float) ($quote['materialsTotal'] ?? 0),
            'special_services_total' => (float) ($quote['specialServicesTotal'] ?? 0),
            'travel_fee' => (float) $booking->travel_fee,
            'travel_distance_km' => $booking->travel_distance_km,
            'admin_margin_amount' => (float) $booking->admin_margin_amount,
            'extension_fee_total' => (float) $booking->extension_fee_total,
            'cancellation_fee' => (float) $booking->cancellation_fee,
            'total_price' => (float) $booking->total_price,
            'is_pricing_final' => (bool) $booking->is_pricing_final,
            'pricing_snapshot' => [
                'contractVersion' => 'room_period_v2',
                'algorithmVersion' => $quote['algorithmVersion'] ?? null,
                'materialsTotal' => (float) ($quote['materialsTotal'] ?? 0),
                'specialServicesTotal' => (float) ($quote['specialServicesTotal'] ?? 0),
                'requiredSimultaneousWorkers' => $requiredWorkers,
                'currency' => $quote['currency'] ?? config('app.currency', 'SYP'),
                'supervisor' => $quote['supervisor'] ?? null,
                'supervisorFeeIncluded' => (float) ($quote['supervisor']['fee'] ?? 0),
                'supervisorCompensationHourlyRate' => $requiredWorkers > 1 ? max(0.0, (float) CleaningRuntimeSettings::financial()->supervisor_compensation_hourly_rate) : null,
            ],
        ]);

        $booking->roomPeriods()->update(['cleaning_booking_session_id' => $session->id]);

        return $session->fresh() ?? $session;
    }

    /**
     * @return array<string, mixed>
     */
    public function initializeBooking(int $bookingId, bool $apply = false): array
    {
        if (! $apply) {
            $booking = CleaningBooking::query()->find($bookingId);

            return $booking instanceof CleaningBooking
                ? $this->evaluate($booking, false)
                : $this->missingResult($bookingId);
        }

        return DB::transaction(function () use ($bookingId): array {
            $booking = CleaningBooking::query()->whereKey($bookingId)->lockForUpdate()->first();
            if (! $booking instanceof CleaningBooking) {
                return $this->missingResult($bookingId);
            }

            return $this->evaluate($booking, true);
        }, 3);
    }

    /**
     * @return array<string, mixed>
     */
    private function evaluate(CleaningBooking $booking, bool $apply): array
    {
        $booking->loadMissing(['sessions', 'roomPeriods.room']);
        $sessions = $booking->sessions;
        $roomPeriods = $booking->roomPeriods;
        $hasTimePeriod = $booking->roomSchedules()->exists();
        $hasScheduledSessionContract = $sessions->contains(
            fn (CleaningBookingSession $session): bool => in_array(
                (string) $session->session_type,
                [CleaningBookingSession::TYPE_RECURRING_CLEANING, CleaningBookingSession::TYPE_OPEN_TIME],
                true,
            )
        );

        if ((string) $booking->property_type === 'event_assistance'
            || ($roomPeriods->isEmpty() && ! $hasTimePeriod && ! $hasScheduledSessionContract)) {
            return $this->result($booking, 'skipped', 'not_supported_v2_schedule', []);
        }

        $bookingStatus = (string) ($booking->status?->value ?? $booking->status);
        if (in_array($bookingStatus, ['completed', 'cancelled'], true)) {
            return $this->result($booking, 'skipped', 'terminal_booking_requires_reconciliation', []);
        }

        if ($this->hasAttendanceOrWork($booking)) {
            return $this->result($booking, 'skipped', 'attendance_or_work_requires_reconciliation', []);
        }

        if ($this->hasCustodyHistory($booking)) {
            return $this->result($booking, 'skipped', 'custody_requires_reconciliation', []);
        }

        if ($roomPeriods->isNotEmpty()) {
            $roomPeriodCheck = $this->validateRoomPeriodFoundation($booking);
            if ($roomPeriodCheck !== null) {
                return $this->result($booking, 'skipped', $roomPeriodCheck, []);
            }

            if ($sessions->isEmpty()) {
                if (! $apply) {
                    return $this->result($booking, 'would_initialize', 'room_period_visit_missing', []);
                }

                $session = $this->materializeBackfilledRoomPeriodVisit($booking);

                return $this->result($booking, 'initialized', null, [(int) $session->id]);
            }

            if ($sessions->count() !== 1 || (int) $sessions->first()->sequence !== 1) {
                return $this->result($booking, 'skipped', 'ambiguous_room_period_session_state', []);
            }

            $session = $sessions->first();
            if ($roomPeriods->contains(fn ($period): bool => $period->cleaning_booking_session_id !== null
                && (int) $period->cleaning_booking_session_id !== (int) $session->id)) {
                return $this->result($booking, 'skipped', 'room_period_session_link_conflict', []);
            }

            if (! $this->matchesRoomPeriodSessionFoundation($booking, $session)) {
                return $this->result($booking, 'skipped', 'room_period_session_provenance_mismatch', []);
            }

            if ($session->isSupervisionInitialized()
                && $roomPeriods->every(fn ($period): bool => (int) $period->cleaning_booking_session_id === (int) $session->id)) {
                return $this->result($booking, 'unchanged', null, [(int) $session->id]);
            }

            if (! $apply) {
                return $this->result($booking, 'would_initialize', 'room_period_session_needs_initialization', [(int) $session->id]);
            }

            $this->initializeExistingSession($session);
            $booking->roomPeriods()->whereNull('cleaning_booking_session_id')->update([
                'cleaning_booking_session_id' => $session->id,
            ]);

            return $this->result($booking, 'initialized', null, [(int) $session->id]);
        }

        if ($sessions->isEmpty()) {
            return $this->result($booking, 'skipped', 'v2_schedule_has_no_sessions', []);
        }

        if ($sessions->contains(fn (CleaningBookingSession $session): bool => $session->requiredWorkerCount() > 1)
            && ! $this->hasApprovedV2GroupProvenance($booking, $sessions)) {
            return $this->result($booking, 'skipped', 'unproven_v2_group_supervision', []);
        }

        if ($sessions->contains(fn (CleaningBookingSession $session): bool => in_array(
            (string) ($session->status?->value ?? $session->status),
            CleaningBookingSessionStatus::terminalValues(),
            true,
        )
            || (float) $session->duration_hours <= 0
            || $session->scheduled_date === null
            || mb_trim((string) $session->scheduled_time) === '')) {
            return $this->result($booking, 'skipped', 'session_state_requires_reconciliation', []);
        }

        $uninitialized = $sessions->filter(
            fn (CleaningBookingSession $session): bool => ! $session->isSupervisionInitialized()
        );
        if ($uninitialized->isEmpty()) {
            return $this->result($booking, 'unchanged', null, $sessions->modelKeys());
        }

        if (! $apply) {
            return $this->result($booking, 'would_initialize', 'existing_sessions_need_initialization', $uninitialized->modelKeys());
        }

        foreach ($uninitialized as $session) {
            $this->initializeExistingSession($session);
        }

        return $this->result($booking, 'initialized', null, $sessions->modelKeys());
    }

    private function validateRoomPeriodFoundation(CleaningBooking $booking): ?string
    {
        $periods = $booking->roomPeriods;
        if ($periods->contains(fn ($period): bool => (int) $period->visit_sequence !== 1)) {
            return 'unsupported_room_period_visit_sequence';
        }

        if ($periods->contains(fn ($period): bool => $period->cleaning_booking_room_id === null
            || $period->room === null
            || (int) $period->room->cleaning_booking_id !== (int) $booking->id)) {
            return 'unproven_room_period_room_links';
        }

        if ($periods->contains(fn ($period): bool => $period->scheduled_date === null
            || mb_trim((string) $period->scheduled_time) === ''
            || (int) $period->estimated_duration_minutes <= 0)) {
            return 'invalid_room_period_schedule';
        }

        $details = is_array($booking->property_details) ? $booking->property_details : [];
        $approvedTotal = $details['_room_period_v2_approved_total'] ?? null;
        $algorithm = $details['_room_period_v2_quote_algorithm'] ?? null;
        $components = $this->roomPeriodFinancialComponents($periods);
        if (! is_numeric($approvedTotal)
            || ! is_string($algorithm)
            || $algorithm === ''
            || abs((float) $approvedTotal - (float) $booking->total_price) > 0.01
            || $components === null
            || abs(
                (float) $booking->addons_total
                - ($components['materialsTotal'] + $components['specialServicesTotal'])
            ) > 0.01) {
            return 'unproven_room_period_financial_provenance';
        }

        return null;
    }

    private function matchesRoomPeriodSessionFoundation(
        CleaningBooking $booking,
        CleaningBookingSession $session,
    ): bool {
        $components = $this->roomPeriodFinancialComponents($booking->roomPeriods);
        if ($components === null) {
            return false;
        }

        [$scheduledDate, $scheduledTime, $durationHours] = $this->roomPeriodEnvelope($booking->roomPeriods);
        $status = (string) ($session->status?->value ?? $session->status);
        $actualTime = mb_substr((string) $session->scheduled_time, 0, 5);

        return (int) $session->sequence === 1
            && $session->scheduled_date?->toDateString() === $scheduledDate
            && $actualTime === $scheduledTime
            && $this->moneyEquals((float) $session->duration_hours, $durationHours)
            && (int) $session->required_workers === max(1, (int) $booking->number_of_workers)
            && $status === CleaningBookingSessionStatus::Scheduled->value
            && $this->moneyEquals((float) $session->base_price, (float) $booking->base_price)
            && $this->moneyEquals((float) $session->addons_total, (float) $booking->addons_total)
            && $this->moneyEquals((float) $session->materials_total, $components['materialsTotal'])
            && $this->moneyEquals((float) $session->special_services_total, $components['specialServicesTotal'])
            && $this->moneyEquals((float) $session->travel_fee, (float) $booking->travel_fee)
            && $this->moneyEquals((float) $session->admin_margin_amount, (float) $booking->admin_margin_amount)
            && $this->moneyEquals((float) $session->extension_fee_total, (float) $booking->extension_fee_total)
            && $this->moneyEquals((float) $session->total_price, (float) $booking->total_price);
    }

    private function hasApprovedV2GroupProvenance(CleaningBooking $booking, iterable $sessions): bool
    {
        $details = is_array($booking->property_details) ? $booking->property_details : [];
        $contract = $details['_time_period_v2'] ?? null;
        $supervisor = $details['_supervisor_pricing'] ?? null;
        $approvedTotal = $details['_room_period_v2_approved_total'] ?? null;
        if (! is_array($contract)
            || (int) ($contract['contractVersion'] ?? 0) !== 2
            || ! is_numeric($approvedTotal)
            || ! $this->moneyEquals((float) $approvedTotal, (float) $booking->total_price)
            || ! is_array($contract['pricing'] ?? null)
            || ($contract['pricing']['isPricingFinal'] ?? false) !== true
            || ($contract['reconciliationRequired'] ?? true) !== false
            || ! is_array($contract['pricing']['supervisor'] ?? null)
            || ! is_array($supervisor)
            || ! is_numeric($supervisor['fee'] ?? null)
            || ! is_numeric($contract['pricing']['supervisor']['fee'] ?? null)
            || ! $this->moneyEquals(
                (float) $supervisor['fee'],
                (float) $contract['pricing']['supervisor']['fee'],
            )
            || ! is_numeric($contract['pricing']['totalPrice'] ?? null)
            || ! $this->moneyEquals(
                (float) $contract['pricing']['totalPrice'],
                (float) $booking->total_price,
            )
            || ! is_numeric($contract['requiredSimultaneousWorkers'] ?? null)
            || (int) $contract['requiredSimultaneousWorkers'] <= 1
            || (int) $contract['requiredSimultaneousWorkers'] !== (int) $booking->number_of_workers) {
            return false;
        }

        $visits = collect((array) ($contract['visits'] ?? []))->keyBy(
            static fn (array $visit): int => (int) ($visit['sequence'] ?? 0)
        );
        $sessionCollection = collect($sessions);
        if ($visits->count() !== $sessionCollection->count()) {
            return false;
        }

        foreach ($sessionCollection as $session) {
            if (! $session instanceof CleaningBookingSession) {
                return false;
            }
            $visit = $visits->get((int) $session->sequence);
            if (! is_array($visit)
                || (int) data_get($session->pricing_snapshot, 'contractVersion', 0) !== 2) {
                return false;
            }

            $startAt = $visit['startAt'] ?? collect((array) ($visit['roomSchedules'] ?? []))->min('startAt');
            if (! is_string($startAt) || $startAt === '') {
                return false;
            }
            $start = CarbonImmutable::parse($startAt, config('app.timezone'));
            $expectedDurationMinutes = (int) ($visit['estimatedDurationMinutes'] ?? 0);
            $expectedWorkers = (int) ($visit['requiredSimultaneousWorkers'] ?? $contract['requiredSimultaneousWorkers']);
            if ($expectedDurationMinutes <= 0
                || $expectedWorkers <= 1
                || $session->scheduled_date?->toDateString() !== $start->toDateString()
                || mb_substr((string) $session->scheduled_time, 0, 5) !== $start->format('H:i')
                || ! $this->moneyEquals((float) $session->duration_hours, round($expectedDurationMinutes / 60, 2))
                || (int) $session->required_workers !== $expectedWorkers) {
                return false;
            }
        }

        return true;
    }

    private function moneyEquals(float $actual, float $expected): bool
    {
        return abs($actual - $expected) <= 0.01;
    }

    private function materializeBackfilledRoomPeriodVisit(CleaningBooking $booking): CleaningBookingSession
    {
        $periods = $booking->roomPeriods;
        [$scheduledDate, $scheduledTime, $durationHours] = $this->roomPeriodEnvelope($periods);
        $components = $this->roomPeriodFinancialComponents($periods);
        if ($components === null) {
            throw new RuntimeException('Signed room-period financial components are unavailable.');
        }
        $specialServicesTotal = $components['specialServicesTotal'];
        $materialsTotal = $components['materialsTotal'];
        $requiredWorkers = max(1, (int) $booking->number_of_workers);
        $details = (array) $booking->property_details;

        $session = $booking->sessions()->create([
            'sequence' => 1,
            'session_type' => null,
            'calculation_mode' => 'task',
            'scheduled_date' => $scheduledDate,
            'scheduled_time' => $scheduledTime,
            'duration_hours' => $durationHours,
            'required_workers' => $requiredWorkers,
            'supervision_mode' => $this->supervisionMode($requiredWorkers),
            'supervision_initialized_at' => now(),
            'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
            'status' => CleaningBookingSessionStatus::Scheduled,
            'base_price' => (float) $booking->base_price,
            'addons_total' => (float) $booking->addons_total,
            'materials_total' => $materialsTotal,
            'special_services_total' => $specialServicesTotal,
            'travel_fee' => (float) $booking->travel_fee,
            'travel_distance_km' => $booking->travel_distance_km,
            'admin_margin_amount' => (float) $booking->admin_margin_amount,
            'extension_fee_total' => (float) $booking->extension_fee_total,
            'cancellation_fee' => (float) $booking->cancellation_fee,
            'total_price' => (float) $booking->total_price,
            'is_pricing_final' => (bool) $booking->is_pricing_final,
            'pricing_snapshot' => [
                'contractVersion' => 'room_period_v2_backfill',
                'algorithmVersion' => $details['_room_period_v2_quote_algorithm'],
                'materialsTotal' => $materialsTotal,
                'specialServicesTotal' => $specialServicesTotal,
                'requiredSimultaneousWorkers' => $requiredWorkers,
                'approvedTotal' => (float) $details['_room_period_v2_approved_total'],
                // Backfill only creates a new session after attendance/work
                // guards; an existing session's historical payout is untouched.
                'supervisorFeeIncluded' => (float) data_get($details, '_supervisor_pricing.fee', 0),
            ],
        ]);

        $booking->roomPeriods()->update(['cleaning_booking_session_id' => $session->id]);

        return $session->fresh() ?? $session;
    }

    private function initializeExistingSession(CleaningBookingSession $session): void
    {
        $requiredWorkers = $session->requiredWorkerCount();
        $session->forceFill([
            'supervision_mode' => $this->supervisionMode($requiredWorkers),
            'supervision_initialized_at' => now(),
        ])->save();
    }

    private function hasAttendanceOrWork(CleaningBooking $booking): bool
    {
        if ($booking->started_travel_at !== null
            || $booking->arrived_at !== null
            || $booking->work_started_at !== null
            || $booking->work_finished_at !== null) {
            return true;
        }

        if ($booking->workerAssignments()
            ->where(function ($query): void {
                $query->whereNotNull('accepted_at')
                    ->orWhereNotNull('started_travel_at')
                    ->orWhereNotNull('arrived_at')
                    ->orWhereNotNull('work_started_at')
                    ->orWhereNotNull('work_finished_at')
                    ->orWhereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
            })->exists()) {
            return true;
        }

        return $booking->sessions()->where(function ($query): void {
            $query->whereNotNull('started_travel_at')
                ->orWhereNotNull('arrived_at')
                ->orWhereNotNull('work_started_at')
                ->orWhereNotNull('work_finished_at')
                ->orWhereNotNull('customer_completed_at');
        })->exists()
            || DB::table('cleaning_booking_session_worker_assignments as assignments')
                ->join('cleaning_booking_sessions as sessions', 'sessions.id', '=', 'assignments.cleaning_booking_session_id')
                ->where('sessions.cleaning_booking_id', $booking->id)
                ->where(function ($query): void {
                    $query->whereNotNull('assignments.accepted_at')
                        ->orWhereNotNull('assignments.started_travel_at')
                        ->orWhereNotNull('assignments.arrived_at')
                        ->orWhereNotNull('assignments.work_started_at')
                        ->orWhereNotNull('assignments.work_finished_at')
                        ->orWhereIn('assignments.status', CleaningBookingWorkerAssignmentStatus::acceptedValues());
                })->exists();
    }

    /**
     * @return array{materialsTotal:float,specialServicesTotal:float}|null
     */
    private function roomPeriodFinancialComponents(iterable $periods): ?array
    {
        $materialsByVisitAndType = [];
        $specialServicesTotal = 0.0;

        foreach ($periods as $period) {
            $snapshot = $period->pricing_snapshot;
            if (! is_array($snapshot)
                || ! array_key_exists('specialServicesTotal', $snapshot)
                || ! array_key_exists('materials', $snapshot)
                || ! is_array($snapshot['materials'])
                || ! array_key_exists('specialServices', $snapshot)
                || ! is_array($snapshot['specialServices'])) {
                return null;
            }

            $periodSpecialServicesTotal = 0.0;
            foreach ($snapshot['specialServices'] as $service) {
                if (! is_array($service) || ! is_numeric($service['totalPrice'] ?? null)) {
                    return null;
                }
                $periodSpecialServicesTotal += (float) $service['totalPrice'];
            }
            if (abs($periodSpecialServicesTotal - (float) $snapshot['specialServicesTotal']) > 0.01) {
                return null;
            }
            $specialServicesTotal += $periodSpecialServicesTotal;
            foreach ($snapshot['materials'] as $material) {
                if (! is_array($material)
                    || ! is_numeric($material['materialTypeId'] ?? null)
                    || ! is_numeric($material['totalPrice'] ?? null)) {
                    return null;
                }

                $key = (int) $period->visit_sequence.':'.(int) $material['materialTypeId'];
                $materialsByVisitAndType[$key] = max(
                    (float) ($materialsByVisitAndType[$key] ?? 0),
                    (float) $material['totalPrice'],
                );
            }
        }

        return [
            'materialsTotal' => round(array_sum($materialsByVisitAndType), 2),
            'specialServicesTotal' => round($specialServicesTotal, 2),
        ];
    }

    private function hasCustodyHistory(CleaningBooking $booking): bool
    {
        if ($booking->materialKit()->whereNotNull('received_at')->exists()) {
            return true;
        }

        return CleaningEquipmentReservation::query()
            ->where(function ($query) use ($booking): void {
                $query->where('cleaning_booking_id', $booking->id)
                    ->orWhereHas('bookingSpecialService', fn ($service) => $service->where('cleaning_booking_id', $booking->id));
            })
            ->where(function ($query): void {
                $query->whereNotNull('handed_over_at')
                    ->orWhereNotNull('acknowledged_at')
                    ->orWhereNotNull('returned_at');
            })
            ->exists();
    }

    /**
     * @return array{0:string,1:string,2:float}
     */
    private function roomPeriodEnvelope(iterable $periods): array
    {
        $firstStart = null;
        $latestEnd = null;

        foreach ($periods as $period) {
            $start = CarbonImmutable::parse(
                $period->scheduled_date->format('Y-m-d').' '.mb_substr((string) $period->scheduled_time, 0, 5),
                config('app.timezone'),
            );
            $end = $start->addMinutes(max(1, (int) $period->estimated_duration_minutes));
            $firstStart = $firstStart === null || $start->lessThan($firstStart) ? $start : $firstStart;
            $latestEnd = $latestEnd === null || $end->greaterThan($latestEnd) ? $end : $latestEnd;
        }

        if (! $firstStart instanceof CarbonImmutable || ! $latestEnd instanceof CarbonImmutable) {
            throw new RuntimeException('Room-period schedule envelope is unavailable.');
        }

        return [
            $firstStart->toDateString(),
            $firstStart->format('H:i'),
            round(max(1, $firstStart->diffInMinutes($latestEnd)) / 60, 2),
        ];
    }

    private function supervisionMode(int $requiredWorkers): string
    {
        return $requiredWorkers > 1
            ? CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
            : CleaningBookingSession::SUPERVISION_MODE_WORKER;
    }

    /**
     * @param  array<int, int|string>  $sessionIds
     * @return array<string, mixed>
     */
    private function result(CleaningBooking $booking, string $status, ?string $reason, array $sessionIds): array
    {
        return [
            'bookingId' => (int) $booking->id,
            'status' => $status,
            'reason' => $reason,
            'sessionIds' => array_values(array_map('intval', $sessionIds)),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function missingResult(int $bookingId): array
    {
        return [
            'bookingId' => $bookingId,
            'status' => 'skipped',
            'reason' => 'booking_not_found',
            'sessionIds' => [],
        ];
    }
}
