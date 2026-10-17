<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoomPeriod;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningRoomSchedule;
use Throwable;

final class CleaningV2VisitExecutionProvenanceService
{
    /**
     * @return array{valid:bool,reasonCodes:list<string>,taskCandidates:list<array{taskKey:string,sourceKind:string,requiredWorkers:int}>}
     */
    public function evaluate(CleaningBooking $booking, CleaningBookingSession $session): array
    {
        if ((int) $session->cleaning_booking_id !== (int) $booking->id) {
            return $this->result([], ['session_booking_mismatch']);
        }

        if ((string) $booking->booking_kind === 'supervised_team'
            || ! in_array((string) $booking->booking_kind, ['standard', 'open_time'], true)
            || (int) $booking->number_of_workers <= 1) {
            return $this->result([], ['unsupported_contract']);
        }

        if ($session->isTerminal()) {
            return $this->result([], ['terminal']);
        }

        $schedules = $booking->roomSchedules()
            ->where('cleaning_booking_session_id', $session->id)
            ->with('room')
            ->orderBy('id')
            ->get();
        $periods = $session->roomPeriods()->with('room')->orderBy('id')->get();

        if ($schedules->isNotEmpty() && $periods->isNotEmpty()) {
            return $this->result([], ['ambiguous_task_source']);
        }

        if ($schedules->isNotEmpty()) {
            return $this->scheduleCandidates($booking, $session, $schedules->all());
        }

        if ($periods->isNotEmpty()) {
            return $this->roomPeriodCandidates($booking, $session, $periods->all());
        }

        return $this->visitScopeCandidate($booking, $session);
    }

    /**
     * @param  list<CleaningRoomSchedule>  $schedules
     * @return array{valid:bool,reasonCodes:list<string>,taskCandidates:list<array{taskKey:string,sourceKind:string,requiredWorkers:int}>}
     */
    private function scheduleCandidates(CleaningBooking $booking, CleaningBookingSession $session, array $schedules): array
    {
        $snapshot = $session->pricing_snapshot;
        $visitKey = is_array($snapshot) ? mb_trim((string) ($snapshot['visitKey'] ?? '')) : '';
        if ((int) ($snapshot['contractVersion'] ?? 0) !== 2 || $visitKey === '' || ! $session->is_pricing_final) {
            return $this->result([], ['unsigned_task_source']);
        }

        $details = is_array($booking->property_details) ? $booking->property_details : [];
        $contract = $details['_time_period_v2'] ?? null;
        $approvedTotal = $details['_room_period_v2_approved_total'] ?? null;
        $timezone = (string) config('cleaning_booking_v2.timezone', 'Asia/Damascus');
        if (! is_array($contract)
            || (int) ($contract['contractVersion'] ?? 0) !== 2
            || ($contract['reconciliationRequired'] ?? true) !== false
            || ! is_array($contract['pricing'] ?? null)
            || ($contract['pricing']['isPricingFinal'] ?? false) !== true
            || ! is_array($contract['schedulingPolicy'] ?? null)
            || mb_trim((string) ($contract['schedulingPolicy']['timezone'] ?? '')) === ''
            || ! is_numeric($approvedTotal)
            || ! is_numeric($contract['pricing']['totalPrice'] ?? null)
            || ! is_numeric($contract['requiredSimultaneousWorkers'] ?? null)) {
            return $this->result([], ['unsigned_task_source']);
        }
        if (! $this->moneyEquals((float) $approvedTotal, (float) $booking->total_price)
            || ! $this->moneyEquals((float) $contract['pricing']['totalPrice'], (float) $booking->total_price)
            || (int) $contract['requiredSimultaneousWorkers'] !== (int) $booking->number_of_workers
            || (string) $contract['schedulingPolicy']['timezone'] !== $timezone) {
            return $this->result([], ['invalid_task_source']);
        }

        $visits = collect((array) ($contract['visits'] ?? []));
        $approvedVisits = $visits->filter(
            static fn (mixed $visit): bool => is_array($visit)
                && (int) ($visit['sequence'] ?? 0) === (int) $session->sequence
        )->values();
        if ($visits->count() !== $booking->sessions()->count()
            || $approvedVisits->count() !== 1) {
            return $this->result([], ['invalid_task_source']);
        }

        $approvedVisit = $approvedVisits->first();
        if (! is_array($approvedVisit)
            || mb_trim((string) ($approvedVisit['visitKey'] ?? '')) !== $visitKey
            || (int) ($approvedVisit['requiredSimultaneousWorkers'] ?? 0) !== $session->requiredWorkerCount()
            || (int) ($approvedVisit['estimatedDurationMinutes'] ?? 0) <= 0
            || ! $this->moneyEquals(
                (float) $session->duration_hours,
                round(((int) $approvedVisit['estimatedDurationMinutes']) / 60, 2),
            )) {
            return $this->result([], ['invalid_task_source']);
        }

        $approvedSchedules = collect((array) ($approvedVisit['roomSchedules'] ?? []));
        if ($approvedSchedules->count() !== count($schedules)) {
            return $this->result([], ['invalid_task_source']);
        }

        $candidates = [];
        $starts = [];
        $approvedKeys = [];
        foreach ($schedules as $schedule) {
            $approvedMatches = $approvedSchedules->filter(
                static fn (mixed $approved): bool => is_array($approved)
                    && mb_trim((string) ($approved['scheduleKey'] ?? '')) === mb_trim((string) $schedule->schedule_key)
            )->values();
            $approved = $approvedMatches->count() === 1 ? $approvedMatches->first() : null;
            if ((int) $schedule->cleaning_booking_id !== (int) $booking->id
                || (int) $schedule->cleaning_booking_session_id !== (int) $session->id
                || $schedule->room === null
                || (int) $schedule->room->cleaning_booking_id !== (int) $booking->id
                || mb_trim((string) $schedule->room->client_key) === ''
                || mb_trim((string) $schedule->schedule_key) === ''
                || mb_trim((string) $schedule->visit_key) !== $visitKey
                || (string) $schedule->timezone !== $timezone
                || $schedule->starts_at === null
                || $schedule->ends_at === null
                || $schedule->ends_at->lessThanOrEqualTo($schedule->starts_at)
                || (int) $schedule->required_workers <= 0
                || (int) $schedule->required_workers > $session->requiredWorkerCount()
                || ! is_array($approved)
                || mb_trim((string) ($approved['roomKey'] ?? '')) !== mb_trim((string) $schedule->room->client_key)
                || (int) ($approved['numberOfWorkers'] ?? 0) !== (int) $schedule->required_workers
                || (int) ($approved['estimatedWorkMinutes'] ?? 0) !== (int) $schedule->work_minutes
                || (int) ($approved['estimatedDurationMinutes'] ?? 0) !== (int) $schedule->duration_minutes
                || ! $this->sameInstant($approved['startAt'] ?? null, $schedule->starts_at, $timezone)
                || ! $this->sameInstant($approved['endAt'] ?? null, $schedule->ends_at, $timezone)) {
                return $this->result([], ['invalid_task_source']);
            }

            $starts[] = CarbonImmutable::instance($schedule->starts_at)->setTimezone($timezone);
            $approvedKeys[] = (string) $schedule->schedule_key;

            $candidates[] = [
                'taskKey' => 'schedule:'.(string) $schedule->schedule_key,
                'sourceKind' => 'room_schedule',
                'requiredWorkers' => (int) $schedule->required_workers,
            ];
        }

        $approvedScheduleKeys = $approvedSchedules
            ->map(static fn (mixed $approved): string => is_array($approved)
                ? mb_trim((string) ($approved['scheduleKey'] ?? ''))
                : '')
            ->all();
        sort($approvedScheduleKeys);
        sort($approvedKeys);
        if (count(array_unique($approvedScheduleKeys)) !== count($approvedScheduleKeys)
            || array_values(array_unique($approvedKeys)) !== array_values(array_unique($approvedScheduleKeys))
            || ! $this->matchesSessionAnchor($session, collect($starts)->min())) {
            return $this->result([], ['invalid_task_source']);
        }

        return $this->uniqueCandidates($candidates);
    }

    /**
     * @param  list<CleaningBookingRoomPeriod>  $periods
     * @return array{valid:bool,reasonCodes:list<string>,taskCandidates:list<array{taskKey:string,sourceKind:string,requiredWorkers:int}>}
     */
    private function roomPeriodCandidates(CleaningBooking $booking, CleaningBookingSession $session, array $periods): array
    {
        $snapshot = $session->pricing_snapshot;
        $contractVersion = is_array($snapshot) ? (string) ($snapshot['contractVersion'] ?? '') : '';
        if (! in_array($contractVersion, ['room_period_v2', 'room_period_v2_backfill'], true)
            || ! $session->is_pricing_final) {
            return $this->result([], ['unsigned_task_source']);
        }

        $details = is_array($booking->property_details) ? $booking->property_details : [];
        $approvedTotal = $details['_room_period_v2_approved_total'] ?? null;
        $approvedAlgorithm = mb_trim((string) ($details['_room_period_v2_quote_algorithm'] ?? ''));
        $snapshotAlgorithm = is_array($snapshot) ? mb_trim((string) ($snapshot['algorithmVersion'] ?? '')) : '';
        if (! is_numeric($approvedTotal)
            || $approvedAlgorithm === ''
            || $snapshotAlgorithm === ''
            || ! is_numeric($snapshot['requiredSimultaneousWorkers'] ?? null)) {
            return $this->result([], ['unsigned_task_source']);
        }
        if (! $this->moneyEquals((float) $approvedTotal, (float) $booking->total_price)
            || $snapshotAlgorithm !== $approvedAlgorithm
            || (int) $snapshot['requiredSimultaneousWorkers'] !== $session->requiredWorkerCount()) {
            return $this->result([], ['invalid_task_source']);
        }

        if ($contractVersion === 'room_period_v2_backfill'
            && ! is_numeric($snapshot['approvedTotal'] ?? null)) {
            return $this->result([], ['unsigned_task_source']);
        }
        if ($contractVersion === 'room_period_v2_backfill'
            && ! $this->moneyEquals((float) $snapshot['approvedTotal'], (float) $approvedTotal)) {
            return $this->result([], ['invalid_task_source']);
        }

        foreach ($periods as $period) {
            if (! is_array($period->pricing_snapshot) || $period->pricing_snapshot === []) {
                return $this->result([], ['unsigned_task_source']);
            }
        }

        $candidates = [];
        $starts = [];
        $roomIds = [];
        $roomKeys = [];
        foreach ($periods as $period) {
            $periodSnapshot = $period->pricing_snapshot;
            if ((int) $period->cleaning_booking_id !== (int) $booking->id
                || (int) $period->cleaning_booking_session_id !== (int) $session->id
                || $period->room === null
                || (int) $period->room->cleaning_booking_id !== (int) $booking->id
                || mb_trim((string) $period->room->room_type) !== mb_trim((string) $period->room_type)
                || mb_trim((string) $period->room->room_size) !== mb_trim((string) $period->room_size)
                || (int) $period->visit_sequence !== (int) $session->sequence
                || mb_trim((string) $period->client_key) === ''
                || $period->scheduled_date === null
                || mb_trim((string) $period->scheduled_time) === ''
                || (int) $period->estimated_duration_minutes <= 0
                || (int) $period->required_workers <= 0
                || (int) $period->required_workers > $session->requiredWorkerCount()
                || mb_trim((string) ($periodSnapshot['clientKey'] ?? '')) !== mb_trim((string) $period->client_key)
                || (int) ($periodSnapshot['visitSequence'] ?? 0) !== (int) $period->visit_sequence
                || mb_trim((string) ($periodSnapshot['roomType'] ?? '')) !== mb_trim((string) $period->room_type)
                || mb_trim((string) ($periodSnapshot['size'] ?? '')) !== mb_trim((string) $period->room_size)
                || mb_trim((string) ($periodSnapshot['cleaningMode'] ?? '')) !== mb_trim((string) $period->cleaning_mode)
                || mb_trim((string) ($periodSnapshot['scheduledDate'] ?? '')) !== $period->scheduled_date->toDateString()
                || mb_substr(mb_trim((string) ($periodSnapshot['scheduledTime'] ?? '')), 0, 5) !== mb_substr(mb_trim((string) $period->scheduled_time), 0, 5)
                || (int) ($periodSnapshot['numberOfWorkers'] ?? 0) !== (int) $period->required_workers
                || (int) ($periodSnapshot['estimatedWorkMinutes'] ?? 0) !== (int) $period->estimated_work_minutes
                || (int) ($periodSnapshot['estimatedDurationMinutes'] ?? 0) !== (int) $period->estimated_duration_minutes
                || ! is_numeric($periodSnapshot['basePrice'] ?? null)
                || ! is_numeric($periodSnapshot['specialServicesTotal'] ?? null)
                || ! $this->moneyEquals((float) $periodSnapshot['basePrice'], (float) $period->base_price)
                || ! $this->moneyEquals((float) $periodSnapshot['specialServicesTotal'], (float) $period->addons_total)
                || ! $this->moneyEquals(
                    (float) $period->total_price,
                    (float) $periodSnapshot['basePrice'] + (float) $periodSnapshot['specialServicesTotal'],
                )) {
                return $this->result([], ['invalid_task_source']);
            }

            $roomIds[] = (int) $period->cleaning_booking_room_id;
            $roomKeys[] = mb_trim((string) $period->client_key);

            try {
                $starts[] = CarbonImmutable::parse(
                    $period->scheduled_date->toDateString().' '.$period->scheduled_time,
                    config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
                );
            } catch (Throwable) {
                return $this->result([], ['invalid_task_source']);
            }

            $candidates[] = [
                'taskKey' => 'room_period:'.(string) $period->client_key,
                'sourceKind' => 'room_period',
                'requiredWorkers' => (int) $period->required_workers,
            ];
        }

        $bookingRooms = $booking->rooms()->orderBy('id')->get();
        $bookingRoomIds = $bookingRooms->pluck('id')->map(static fn (mixed $id): int => (int) $id)->all();
        sort($roomIds);
        sort($roomKeys);
        sort($bookingRoomIds);

        $components = $this->roomPeriodFinancialComponents($periods);
        if (count(array_unique($roomIds)) !== count($roomIds)
            || count(array_unique($roomKeys)) !== count($roomKeys)
            || $roomIds !== $bookingRoomIds
            || $components === null
            || ! $this->moneyEquals(
                $components['specialServicesTotal'] + $components['materialsTotal'],
                (float) $booking->addons_total,
            )
            || ! $this->moneyEquals((float) $session->materials_total, $components['materialsTotal'])
            || ! $this->moneyEquals((float) $session->special_services_total, $components['specialServicesTotal'])
            || ! $this->moneyEquals((float) $session->base_price, (float) $booking->base_price)
            || ! $this->moneyEquals((float) $session->addons_total, (float) $booking->addons_total)
            || ! $this->moneyEquals((float) $session->total_price, (float) $booking->total_price)
            || ! $this->matchesSessionAnchor($session, collect($starts)->min())) {
            return $this->result([], ['invalid_task_source']);
        }

        return $this->uniqueCandidates($candidates);
    }

    /**
     * @param  list<CleaningBookingRoomPeriod>  $periods
     * @return array{materialsTotal:float,specialServicesTotal:float}|null
     */
    private function roomPeriodFinancialComponents(array $periods): ?array
    {
        $materialsByVisitAndType = [];
        $specialServicesTotal = 0.0;

        foreach ($periods as $period) {
            $snapshot = $period->pricing_snapshot;
            if (! is_array($snapshot)
                || ! array_key_exists('specialServicesTotal', $snapshot)
                || ! is_numeric($snapshot['specialServicesTotal'])
                || ! is_array($snapshot['specialServices'] ?? null)
                || ! is_array($snapshot['materials'] ?? null)) {
                return null;
            }

            $periodSpecialServicesTotal = 0.0;
            foreach ($snapshot['specialServices'] as $service) {
                if (! is_array($service) || ! is_numeric($service['totalPrice'] ?? null)) {
                    return null;
                }

                $periodSpecialServicesTotal += (float) $service['totalPrice'];
            }
            if (! $this->moneyEquals($periodSpecialServicesTotal, (float) $snapshot['specialServicesTotal'])) {
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

    /**
     * @return array{valid:bool,reasonCodes:list<string>,taskCandidates:list<array{taskKey:string,sourceKind:string,requiredWorkers:int}>}
     */
    private function visitScopeCandidate(CleaningBooking $booking, CleaningBookingSession $session): array
    {
        $snapshot = $session->pricing_snapshot;
        if (! is_array($snapshot) || ! $session->is_pricing_final || ! $this->hasValidLocalSessionStart($session)) {
            return $this->result([], ['unsigned_task_source']);
        }

        $requiredWorkers = $session->requiredWorkerCount();
        if ($requiredWorkers <= 1 || $requiredWorkers !== (int) $booking->number_of_workers) {
            return $this->result([], ['invalid_task_source']);
        }

        if ((string) $session->session_type === CleaningBookingSession::TYPE_OPEN_TIME
            && (string) $booking->booking_kind === 'open_time') {
            $expectedMinutes = (int) $session->open_time_expected_max_minutes;
            if ((string) ($snapshot['scheduleType'] ?? '') !== CleaningBookingSession::TYPE_OPEN_TIME
                || (int) ($snapshot['workerCount'] ?? 0) !== $requiredWorkers
                || (int) ($snapshot['expectedMaxMinutes'] ?? 0) !== $expectedMinutes
                || $expectedMinutes <= 0) {
                return $this->result([], ['unsigned_task_source']);
            }

            return $this->result([[
                'taskKey' => 'visit_scope',
                'sourceKind' => 'visit_scope',
                'requiredWorkers' => $requiredWorkers,
            ]], []);
        }

        if ((string) $session->session_type === CleaningBookingSession::TYPE_RECURRING_CLEANING
            && (string) $booking->booking_kind === 'standard') {
            $hours = (float) ($snapshot['hoursPerVisit'] ?? 0);
            if ((string) ($snapshot['scheduleType'] ?? '') !== CleaningBookingSession::TYPE_RECURRING_CLEANING
                || (string) ($snapshot['calculationMode'] ?? '') !== 'hours'
                || (int) ($snapshot['requiredWorkers'] ?? 0) !== $requiredWorkers
                || $hours <= 0
                || abs($hours - (float) $session->duration_hours) > 0.01) {
                return $this->result([], ['unsigned_task_source']);
            }

            return $this->result([[
                'taskKey' => 'visit_scope',
                'sourceKind' => 'visit_scope',
                'requiredWorkers' => $requiredWorkers,
            ]], []);
        }

        return $this->result([], ['unsupported_contract']);
    }

    private function hasValidLocalSessionStart(CleaningBookingSession $session): bool
    {
        $date = $session->scheduled_date?->toDateString();
        $time = mb_trim((string) $session->scheduled_time);
        if ($date === null || $time === '') {
            return false;
        }

        try {
            CarbonImmutable::parse(
                $date.' '.$time,
                config('cleaning_booking_v2.timezone', 'Asia/Damascus'),
            );

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    private function matchesSessionAnchor(CleaningBookingSession $session, mixed $candidateStart): bool
    {
        if (! $candidateStart instanceof CarbonImmutable || (float) $session->duration_hours <= 0) {
            return false;
        }

        $date = $session->scheduled_date?->toDateString();
        $time = mb_substr(mb_trim((string) $session->scheduled_time), 0, 5);

        return $date !== null
            && $time !== ''
            && $candidateStart->toDateString() === $date
            && $candidateStart->format('H:i') === $time;
    }

    private function sameInstant(mixed $approved, mixed $persisted, string $timezone): bool
    {
        if (! is_string($approved) || mb_trim($approved) === '' || $persisted === null) {
            return false;
        }

        try {
            return CarbonImmutable::parse($approved, $timezone)->utc()->equalTo(
                CarbonImmutable::instance($persisted)->utc(),
            );
        } catch (Throwable) {
            return false;
        }
    }

    private function moneyEquals(float $actual, float $expected): bool
    {
        return abs($actual - $expected) <= 0.01;
    }

    /**
     * @param  list<array{taskKey:string,sourceKind:string,requiredWorkers:int}>  $candidates
     * @return array{valid:bool,reasonCodes:list<string>,taskCandidates:list<array{taskKey:string,sourceKind:string,requiredWorkers:int}>}
     */
    private function uniqueCandidates(array $candidates): array
    {
        if ($candidates === []) {
            return $this->result([], ['task_source_missing']);
        }

        $keys = array_column($candidates, 'taskKey');
        if (count($keys) !== count(array_unique($keys))) {
            return $this->result([], ['ambiguous_task_source']);
        }

        return $this->result($candidates, []);
    }

    /**
     * @param  list<array{taskKey:string,sourceKind:string,requiredWorkers:int}>  $candidates
     * @param  list<string>  $reasonCodes
     * @return array{valid:bool,reasonCodes:list<string>,taskCandidates:list<array{taskKey:string,sourceKind:string,requiredWorkers:int}>}
     */
    private function result(array $candidates, array $reasonCodes): array
    {
        return [
            'valid' => $reasonCodes === [],
            'reasonCodes' => array_values(array_unique($reasonCodes)),
            'taskCandidates' => $candidates,
        ];
    }
}
