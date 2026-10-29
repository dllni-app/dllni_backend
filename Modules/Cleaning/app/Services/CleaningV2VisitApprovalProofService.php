<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\User\Services\CleaningTimePeriodQuoteService;
use Throwable;

final class CleaningV2VisitApprovalProofService
{
    private const ALGORITHM = 'time-period-v2-2026-10-15';

    private const UTC_FORMAT = 'Y-m-d H:i:s';

    private const LOCAL_FORMAT = 'Y-m-d H:i';

    private const ISO_FORMAT = 'Y-m-d\TH:i:sP';

    public function __construct(private readonly CleaningTimePeriodQuoteService $quotes) {}

    /**
     * @return array{valid:bool,reasonCodes:list<string>,evidenceDigest:?string,taskCandidates:list<array<string,mixed>>}
     */
    public function verify(CleaningBooking $booking, CleaningBookingSession $session): array
    {
        try {
            return $this->verifyFresh($booking, $session);
        } catch (Throwable) {
            return $this->invalid('unverifiable_evidence');
        }
    }

    /** @return array{valid:bool,reasonCodes:list<string>,evidenceDigest:?string,taskCandidates:list<array<string,mixed>>} */
    private function verifyFresh(CleaningBooking $suppliedBooking, CleaningBookingSession $suppliedSession): array
    {
        $booking = CleaningBooking::query()->whereKey($suppliedBooking->getKey())->first();
        $session = CleaningBookingSession::query()->whereKey($suppliedSession->getKey())->first();
        if ($booking === null || $session === null || (int) $session->cleaning_booking_id !== (int) $booking->id) {
            return $this->invalid('booking_session_mismatch');
        }

        $details = is_array($booking->property_details) ? $booking->property_details : [];
        $pricing = $details['_time_period_v2']['pricing'] ?? null;
        if (is_array($pricing) && ($pricing['travelFeePending'] ?? null) === true
            && ($pricing['isPricingFinal'] ?? null) === false) {
            return $this->invalid('travel_fee_pending');
        }

        if ((string) $booking->booking_kind !== 'standard'
            || (int) $booking->number_of_workers <= 1
            || ! $booking->is_pricing_final
            || in_array($this->value($booking->status), ['completed', 'cancelled', 'rejected'], true)) {
            return $this->invalid('unsupported_booking');
        }
        if (! $session->is_pricing_final
            || ! $session->isSupervisionInitialized()
            || (string) $session->supervision_mode !== CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
            || $session->isTerminal()
            || in_array((string) $session->session_type, [CleaningBookingSession::TYPE_OPEN_TIME], true)) {
            return $this->invalid('unsupported_visit');
        }

        $linkedSession = CleaningBookingSession::query()
            ->where('cleaning_booking_id', $booking->id)
            ->where('sequence', $session->sequence)
            ->count();
        $rawSchedules = DB::table('cleaning_room_schedules')
            ->where('cleaning_booking_session_id', $session->id)
            ->orderBy('id')
            ->get();
        if ($linkedSession !== 1 || $rawSchedules->isEmpty()) {
            return $this->invalid('missing_schedule_source');
        }
        if (DB::table('cleaning_booking_room_periods')->where('cleaning_booking_id', $booking->id)->exists()) {
            return $this->invalid('ambiguous_schedule_source');
        }

        $quoteRows = DB::table('cleaning_booking_quotes')
            ->where('cleaning_booking_id', $booking->id)
            ->orderBy('id')
            ->get();
        if ($quoteRows->count() !== 1) {
            return $this->invalid('quote_linkage_invalid');
        }
        $quote = $quoteRows->first();
        if ((int) $quote->customer_id !== (int) $booking->customer_id
            || (int) ($quote->cleaning_booking_id ?? 0) !== (int) $booking->id
            || ! is_string($quote->idempotency_key) || mb_trim($quote->idempotency_key) === ''
            || ! is_string($quote->id) || ! preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $quote->id)
            || ! is_string($quote->request_hash) || ! preg_match('/^[0-9a-f]{64}$/i', $quote->request_hash)
            || ! is_string($quote->signature) || ! preg_match('/^[0-9a-f]{64}$/i', $quote->signature)) {
            return $this->invalid('quote_metadata_invalid');
        }

        $expiresAt = $this->parseUtcDatabaseValue($quote->expires_at ?? null);
        if ($expiresAt === null) {
            return $this->invalid('quote_expiry_invalid');
        }
        $snapshot = $this->decodeSnapshot($quote->snapshot ?? null);
        if ($snapshot === null) {
            return $this->invalid('quote_snapshot_invalid');
        }
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            return $this->invalid('signing_key_unavailable');
        }
        $pricingHash = $this->quotes->pricingHash($snapshot);
        $expected = hash_hmac(
            'sha256',
            $quote->id.'|'.$quote->customer_id.'|'.$quote->request_hash.'|'.$pricingHash.'|'.$expiresAt->getTimestamp(),
            $key,
        );
        if (! hash_equals(mb_strtolower($quote->signature), $expected)) {
            return $this->invalid('quote_authentication_failed');
        }

        $binding = $this->verifySnapshotAndVisit($booking, $session, $snapshot, $rawSchedules);
        if ($binding === null) {
            return $this->invalid('persisted_facts_mismatch');
        }

        $quoteDigest = hash('sha256', $pricingHash.'|'.$expected.'|'.$expiresAt->format(self::UTC_FORMAT));
        $envelope = [
            'verifierVersion' => 1,
            'quoteId' => (string) $quote->id,
            'authenticatedQuoteDigest' => $quoteDigest,
            'bookingId' => (int) $booking->id,
            'customerId' => (int) $booking->customer_id,
            'sessionId' => (int) $session->id,
            'sequence' => (int) $session->sequence,
            'visitKey' => $binding['visitKey'],
            'financialSnapshot' => $binding['financialSnapshot'],
            'sources' => $binding['sources'],
        ];
        $digest = hash('sha256', json_encode($this->canonical($envelope), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return [
            'valid' => true,
            'reasonCodes' => [],
            'evidenceDigest' => $digest,
            'taskCandidates' => $binding['candidates'],
        ];
    }

    /** @return array{visitKey:string,financialSnapshot:array<string,mixed>,sources:list<array<string,mixed>>,candidates:list<array<string,mixed>>}|null */
    private function verifySnapshotAndVisit(CleaningBooking $booking, CleaningBookingSession $session, array $snapshot, iterable $rawSchedules): ?array
    {
        if (($snapshot['algorithmVersion'] ?? null) !== self::ALGORITHM
            || ($snapshot['timezone'] ?? null) !== 'Asia/Damascus'
            || ($snapshot['reconciliationRequired'] ?? true) !== false
            || ! is_array($snapshot['rooms'] ?? null)
            || ! is_array($snapshot['visits'] ?? null)
            || $snapshot['visits'] === []
            || ! $this->isInteger($snapshot['requestedWorkerCount'] ?? null)
            || (int) $snapshot['requestedWorkerCount'] !== (int) $booking->number_of_workers
            || ! $this->isInteger($snapshot['requiredSimultaneousWorkers'] ?? null)
            || (int) $snapshot['requiredSimultaneousWorkers'] < 1
            || (int) $snapshot['requiredSimultaneousWorkers'] > (int) $snapshot['requestedWorkerCount']
            || ! $this->moneyEquals($snapshot['totalPrice'] ?? null, $booking->total_price)) {
            return null;
        }

        $details = is_array($booking->property_details) ? $booking->property_details : [];
        $approved = $details['_time_period_v2'] ?? null;
        if (! is_array($approved)
            || (int) ($approved['contractVersion'] ?? 0) !== 2
            || ($approved['reconciliationRequired'] ?? true) !== false
            || ! is_array($approved['pricing'] ?? null)
            || ($approved['pricing']['isPricingFinal'] ?? false) !== true
            || ! is_array($approved['schedulingPolicy'] ?? null)
            || ($approved['schedulingPolicy']['timezone'] ?? null) !== 'Asia/Damascus'
            || ! $this->moneyEquals($approved['pricing']['totalPrice'] ?? null, $snapshot['totalPrice'])
            || ! $this->moneyEquals($details['_room_period_v2_approved_total'] ?? null, $snapshot['totalPrice'])
            || ! $this->moneyEquals($snapshot['totalPrice'], $booking->total_price)
            || (int) ($approved['requiredSimultaneousWorkers'] ?? 0) !== (int) $snapshot['requiredSimultaneousWorkers']
            || ! $this->sameValue($approved['rooms'] ?? null, $snapshot['rooms'])) {
            return null;
        }

        $sequenceMap = [];
        $keyMap = [];
        foreach ($snapshot['visits'] as $visit) {
            if (! is_array($visit)
                || ! $this->isInteger($visit['sequence'] ?? null)
                || (int) $visit['sequence'] < 1
                || ! is_string($visit['visitKey'] ?? null) || mb_trim($visit['visitKey']) === ''
                || ! is_array($visit['roomSchedules'] ?? null) || $visit['roomSchedules'] === []) {
                return null;
            }
            $sequence = (int) $visit['sequence'];
            $visitKey = (string) $visit['visitKey'];
            if (isset($sequenceMap[$sequence]) || isset($keyMap[$visitKey])) {
                return null;
            }
            $sequenceMap[$sequence] = $visit;
            $keyMap[$visitKey] = true;
        }
        ksort($sequenceMap);
        if (array_keys($sequenceMap) !== range(1, count($sequenceMap))
            || count($sequenceMap) !== CleaningBookingSession::query()->where('cleaning_booking_id', $booking->id)->count()
            || count($sequenceMap) !== count((array) ($approved['visits'] ?? []))) {
            return null;
        }

        foreach ($sequenceMap as $sequence => $signedVisit) {
            $approvedVisit = $this->uniqueByInt((array) ($approved['visits'] ?? []), 'sequence', (int) $sequence);
            $persistedSession = CleaningBookingSession::query()
                ->where('cleaning_booking_id', $booking->id)
                ->where('sequence', $sequence)
                ->first();
            if ($approvedVisit === null || $persistedSession === null
                || ($approvedVisit['visitKey'] ?? null) !== $signedVisit['visitKey']
                || ! $this->visitPresentationMatches($signedVisit, $approvedVisit)) {
                return null;
            }
            $sessionSnapshot = is_array($persistedSession->pricing_snapshot) ? $persistedSession->pricing_snapshot : [];
            if ((int) ($sessionSnapshot['contractVersion'] ?? 0) !== 2
                || ($sessionSnapshot['visitKey'] ?? null) !== $signedVisit['visitKey']
                || ! $persistedSession->is_pricing_final
                || ! $persistedSession->isSupervisionInitialized()
                || (string) $persistedSession->supervision_mode !== ((int) $snapshot['requestedWorkerCount'] > 1
                    ? CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR
                    : CleaningBookingSession::SUPERVISION_MODE_WORKER)
                || ! $this->signedVisitSourcesMatch($booking, $persistedSession, $signedVisit, $snapshot['rooms'], (int) $snapshot['requestedWorkerCount'])
                || ! $this->signedVisitPeriodsMatch($booking, $persistedSession, $signedVisit)) {
                return null;
            }
        }

        $visit = $sequenceMap[(int) $session->sequence] ?? null;
        if (! is_array($visit)) {
            return null;
        }
        $visitKey = (string) $visit['visitKey'];
        $sessionSnapshot = is_array($session->pricing_snapshot) ? $session->pricing_snapshot : [];
        $financial = $visit['financial'] ?? null;
        if (! is_array($financial)
            || ! array_key_exists('includedAdminMargin', $financial)
            || ! array_key_exists('cashRoundingAdjustment', $financial)
            || ! array_key_exists('includedAdminMargin', $sessionSnapshot)
            || ! array_key_exists('cashRoundingAdjustment', $sessionSnapshot)
            || ($sessionSnapshot['visitKey'] ?? null) !== $visitKey
            || (int) ($sessionSnapshot['contractVersion'] ?? 0) !== 2
            || (int) ($visit['sequence'] ?? 0) !== (int) $session->sequence
            || (int) ($snapshot['requestedWorkerCount'] ?? 0) !== $session->requiredWorkerCount()
            || (int) ($visit['estimatedDurationMinutes'] ?? 0) <= 0
            || ! $this->moneyEquals($sessionSnapshot['includedAdminMargin'], $financial['includedAdminMargin'])
            || ! $this->moneyEquals($sessionSnapshot['cashRoundingAdjustment'], $financial['cashRoundingAdjustment'])
            || ! $this->sessionFinancialsMatch($session, $financial)
            || ! $this->sessionAnchorMatches($session, $visit['roomSchedules'])) {
            return null;
        }

        $allSignedScheduleCount = array_sum(array_map(static fn (array $item): int => count($item['roomSchedules']), array_values($sequenceMap)));
        if (DB::table('cleaning_room_schedules')->where('cleaning_booking_id', $booking->id)->count() !== $allSignedScheduleCount) {
            return null;
        }
        $roomRows = DB::table('cleaning_booking_rooms')->where('cleaning_booking_id', $booking->id)->get()->keyBy('id');
        if ($roomRows->count() !== count($snapshot['rooms'])) {
            return null;
        }
        $roomMap = [];
        foreach ($snapshot['rooms'] as $room) {
            if (! is_array($room) || ! is_string($room['roomKey'] ?? null) || isset($roomMap[$room['roomKey']])) {
                return null;
            }
            $roomMap[$room['roomKey']] = $room;
        }

        $signedSchedules = $visit['roomSchedules'];
        $scheduleKeys = [];
        foreach ($signedSchedules as $schedule) {
            if (! is_array($schedule) || ! is_string($schedule['scheduleKey'] ?? null)
                || $schedule['scheduleKey'] === '' || isset($scheduleKeys[$schedule['scheduleKey']])) {
                return null;
            }
            $scheduleKeys[$schedule['scheduleKey']] = $schedule;
        }
        $rows = collect($rawSchedules);
        if ($rows->count() !== count($scheduleKeys)) {
            return null;
        }
        $candidates = [];
        $sources = [];
        $seenRooms = [];
        foreach ($rows as $row) {
            $scheduleKey = (string) $row->schedule_key;
            $signed = $scheduleKeys[$scheduleKey] ?? null;
            $rawStart = $this->parseUtcDatabaseValue($row->starts_at ?? null);
            $rawEnd = $this->parseUtcDatabaseValue($row->ends_at ?? null);
            $room = $roomRows->get($row->cleaning_booking_room_id);
            $roomKey = is_array($signed) ? ($signed['roomKey'] ?? null) : null;
            $signedRoom = is_string($roomKey) ? ($roomMap[$roomKey] ?? null) : null;
            if (! is_array($signed) || $rawStart === null || $rawEnd === null || $rawEnd <= $rawStart
                || $room === null || $signedRoom === null
                || (int) $row->cleaning_booking_id !== (int) $booking->id
                || (int) $row->cleaning_booking_session_id !== (int) $session->id
                || (int) $room->cleaning_booking_id !== (int) $booking->id
                || isset($seenRooms[(int) $room->id])
                || (string) $room->client_key !== $roomKey
                || (string) $room->room_type !== (string) ($signedRoom['roomType'] ?? '')
                || (string) $room->room_size !== (string) ($signedRoom['size'] ?? '')
                || (string) $room->cleaning_mode !== (string) ($signedRoom['cleaningMode'] ?? '')
                || (string) $row->visit_key !== $visitKey
                || (string) $row->timezone !== 'Asia/Damascus'
                || ! $this->isInteger($row->required_workers)
                || (int) $row->required_workers < 1
                || (int) $row->required_workers > $session->requiredWorkerCount()
                || (int) ($signed['numberOfWorkers'] ?? 0) !== (int) $row->required_workers
                || (int) ($signed['estimatedWorkMinutes'] ?? 0) !== (int) $row->work_minutes
                || (int) ($signed['estimatedDurationMinutes'] ?? 0) !== (int) $row->duration_minutes
                || ! $this->sameSignedInstant($signed['startAt'] ?? null, $rawStart)
                || ! $this->sameSignedInstant($signed['endAt'] ?? null, $rawEnd)) {
                return null;
            }
            $seenRooms[(int) $room->id] = true;
            $source = [
                'sourceId' => (int) $row->id,
                'scheduleKey' => $scheduleKey,
                'visitKey' => $visitKey,
                'roomKey' => (string) $roomKey,
                'requiredWorkers' => (int) $row->required_workers,
                'startsAt' => $rawStart->format('Y-m-d\TH:i:s\Z'),
                'endsAt' => $rawEnd->format('Y-m-d\TH:i:s\Z'),
            ];
            $sources[] = $source;
            $candidates[] = [
                'sourceId' => (int) $row->id,
                'taskKey' => 'schedule:'.$scheduleKey,
                'sourceKind' => 'room_schedule',
                'requiredWorkers' => (int) $row->required_workers,
                'startsAt' => $source['startsAt'],
                'endsAt' => $source['endsAt'],
            ];
        }
        usort($sources, static fn (array $left, array $right): int => [$left['scheduleKey'], $left['sourceId']] <=> [$right['scheduleKey'], $right['sourceId']]);
        usort($candidates, static fn (array $left, array $right): int => [$left['taskKey'], $left['sourceId']] <=> [$right['taskKey'], $right['sourceId']]);

        return [
            'visitKey' => $visitKey,
            'financialSnapshot' => $financial,
            'sources' => $sources,
            'candidates' => $candidates,
        ];
    }

    private function visitPresentationMatches(array $signed, array $approved): bool
    {
        if ((int) ($approved['sequence'] ?? 0) !== (int) $signed['sequence']
            || ($approved['visitKey'] ?? null) !== $signed['visitKey']
            || (int) ($approved['requiredSimultaneousWorkers'] ?? 0) !== (int) ($signed['requiredSimultaneousWorkers'] ?? 0)
            || (int) ($approved['estimatedDurationMinutes'] ?? 0) !== (int) ($signed['estimatedDurationMinutes'] ?? 0)
            || ! is_array($approved['roomSchedules'] ?? null)
            || count($approved['roomSchedules']) !== count($signed['roomSchedules'])
            || ! is_array($approved['timePeriods'] ?? null)
            || count($approved['timePeriods']) !== count((array) ($signed['timePeriods'] ?? []))) {
            return false;
        }
        foreach ($signed['roomSchedules'] as $schedule) {
            if (! is_array($schedule)) {
                return false;
            }
            $matches = array_values(array_filter($approved['roomSchedules'], static fn (mixed $item): bool => is_array($item)
                && ($item['scheduleKey'] ?? null) === ($schedule['scheduleKey'] ?? null)));
            if (count($matches) !== 1) {
                return false;
            }
            $item = $matches[0];
            foreach (['roomKey', 'startAt', 'endAt'] as $key) {
                if (($item[$key] ?? null) !== ($schedule[$key] ?? null)) {
                    return false;
                }
            }
            foreach (['numberOfWorkers', 'estimatedWorkMinutes', 'estimatedDurationMinutes'] as $key) {
                if ((int) ($item[$key] ?? -1) !== (int) ($schedule[$key] ?? -2)) {
                    return false;
                }
            }
        }
        foreach ((array) ($signed['timePeriods'] ?? []) as $period) {
            if (! is_array($period)) {
                return false;
            }
            $matches = array_values(array_filter($approved['timePeriods'], static fn (mixed $item): bool => is_array($item)
                && ($item['periodKey'] ?? null) === ($period['periodKey'] ?? null)));
            if (count($matches) !== 1 || ($matches[0]['roomScheduleKeys'] ?? null) !== ($period['roomScheduleKeys'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    /** Verify every sibling's persisted source, not just the visit requested by the caller. */
    private function signedVisitSourcesMatch(CleaningBooking $booking, CleaningBookingSession $session, array $visit, array $signedRooms, int $requestedWorkers): bool
    {
        $schedules = $visit['roomSchedules'] ?? null;
        $financial = $visit['financial'] ?? null;
        $sessionSnapshot = is_array($session->pricing_snapshot) ? $session->pricing_snapshot : [];
        $durationMinutes = $visit['estimatedDurationMinutes'] ?? null;
        $expectedDurationHours = $this->isInteger($durationMinutes) && (int) $durationMinutes > 0
            ? round((int) $durationMinutes / 60, 2)
            : null;
        if (! is_array($schedules) || $schedules === []
            || ! $this->sessionAnchorMatches($session, $schedules)
            || $requestedWorkers !== $session->requiredWorkerCount()
            || ! $this->isInteger($visit['requiredSimultaneousWorkers'] ?? null)
            || (int) $visit['requiredSimultaneousWorkers'] < 1
            || (int) $visit['requiredSimultaneousWorkers'] > $requestedWorkers
            || ! is_array($financial)
            || ! array_key_exists('includedAdminMargin', $financial)
            || ! array_key_exists('cashRoundingAdjustment', $financial)
            || ! array_key_exists('includedAdminMargin', $sessionSnapshot)
            || ! array_key_exists('cashRoundingAdjustment', $sessionSnapshot)
            || $expectedDurationHours === null
            || abs((float) $session->duration_hours - $expectedDurationHours) > 0.0005
            || ! $this->moneyEquals($sessionSnapshot['includedAdminMargin'], $financial['includedAdminMargin'])
            || ! $this->moneyEquals($sessionSnapshot['cashRoundingAdjustment'], $financial['cashRoundingAdjustment'])
            || ! $this->sessionFinancialsMatch($session, $financial)) {
            return false;
        }

        $byKey = [];
        foreach ($schedules as $schedule) {
            if (! is_array($schedule) || ! is_string($schedule['scheduleKey'] ?? null)
                || $schedule['scheduleKey'] === '' || isset($byKey[$schedule['scheduleKey']])) {
                return false;
            }
            $byKey[$schedule['scheduleKey']] = $schedule;
        }
        $rooms = [];
        foreach ($signedRooms as $room) {
            if (! is_array($room) || ! is_string($room['roomKey'] ?? null)
                || isset($rooms[$room['roomKey']])) {
                return false;
            }
            $rooms[$room['roomKey']] = $room;
        }
        $persisted = DB::table('cleaning_room_schedules')
            ->where('cleaning_booking_session_id', $session->id)->get();
        if ($persisted->count() !== count($byKey)) {
            return false;
        }
        $seen = [];
        foreach ($persisted as $row) {
            $signed = $byKey[$row->schedule_key] ?? null;
            $start = $this->parseUtcDatabaseValue($row->starts_at ?? null);
            $end = $this->parseUtcDatabaseValue($row->ends_at ?? null);
            $room = DB::table('cleaning_booking_rooms')->where('id', $row->cleaning_booking_room_id)->first();
            $roomKey = is_array($signed) ? ($signed['roomKey'] ?? null) : null;
            $expectedRoom = is_string($roomKey) ? ($rooms[$roomKey] ?? null) : null;
            if ($signed === null || $start === null || $end === null || $end <= $start
                || $room === null || $expectedRoom === null || isset($seen[$row->cleaning_booking_room_id])
                || (int) $row->cleaning_booking_id !== (int) $booking->id
                || (int) $room->cleaning_booking_id !== (int) $booking->id
                || (string) $row->visit_key !== (string) $visit['visitKey']
                || (string) $row->timezone !== 'Asia/Damascus'
                || (string) $room->client_key !== $roomKey
                || (string) $room->room_type !== (string) ($expectedRoom['roomType'] ?? '')
                || (string) $room->room_size !== (string) ($expectedRoom['size'] ?? '')
                || (string) $room->cleaning_mode !== (string) ($expectedRoom['cleaningMode'] ?? '')
                || (int) $row->required_workers !== (int) ($signed['numberOfWorkers'] ?? 0)
                || (int) $row->work_minutes !== (int) ($signed['estimatedWorkMinutes'] ?? 0)
                || (int) $row->duration_minutes !== (int) ($signed['estimatedDurationMinutes'] ?? 0)
                || ! $this->sameSignedInstant($signed['startAt'] ?? null, $start)
                || ! $this->sameSignedInstant($signed['endAt'] ?? null, $end)) {
                return false;
            }
            $seen[$row->cleaning_booking_room_id] = true;
        }

        return true;
    }

    /** Match original signed period, pivot, material and service evidence without live repricing. */
    private function signedVisitPeriodsMatch(CleaningBooking $booking, CleaningBookingSession $session, array $visit): bool
    {
        $signedPeriods = $visit['timePeriods'] ?? null;
        if (! is_array($signedPeriods)) {
            return false;
        }
        $persisted = DB::table('cleaning_time_periods')
            ->where('cleaning_booking_session_id', $session->id)->get();
        if ($persisted->count() !== count($signedPeriods)) {
            return false;
        }
        $byKey = [];
        foreach ($signedPeriods as $period) {
            if (! is_array($period) || ! is_string($period['periodKey'] ?? null)
                || $period['periodKey'] === '' || isset($byKey[$period['periodKey']])
                || ! is_array($period['roomScheduleKeys'] ?? null)
                || ! is_array($period['materialLines'] ?? null)
                || ! is_array($period['serviceLines'] ?? null)
                || ! is_array($period['materials'] ?? null)) {
                return false;
            }
            $byKey[$period['periodKey']] = $period;
        }

        foreach ($persisted as $row) {
            $period = $byKey[$row->period_key] ?? null;
            $start = $this->parseUtcDatabaseValue($row->starts_at ?? null);
            $end = $this->parseUtcDatabaseValue($row->ends_at ?? null);
            if ($period === null || $start === null || $end === null || $end <= $start
                || (int) $row->cleaning_booking_id !== (int) $booking->id
                || (int) $row->cleaning_booking_session_id !== (int) $session->id
                || (string) $row->timezone !== 'Asia/Damascus'
                || (string) $row->materials_choice !== (string) ($period['materials']['choice'] ?? '')
                || ! $this->sameSignedInstant($period['startAt'] ?? null, $start)
                || ! $this->sameSignedInstant($period['endAt'] ?? null, $end)) {
                return false;
            }

            $links = DB::table('cleaning_time_period_room_schedules as link')
                ->join('cleaning_room_schedules as source', 'source.id', '=', 'link.cleaning_room_schedule_id')
                ->where('link.cleaning_time_period_id', $row->id)
                ->get(['source.id', 'source.schedule_key', 'source.cleaning_booking_id', 'source.cleaning_booking_session_id']);
            $expectedKeys = $period['roomScheduleKeys'];
            $actualKeys = $links->pluck('schedule_key')->all();
            sort($expectedKeys);
            sort($actualKeys);
            if ($actualKeys !== $expectedKeys || count(array_unique($actualKeys)) !== count($actualKeys)) {
                return false;
            }
            foreach ($links as $linked) {
                if ((int) $linked->cleaning_booking_id !== (int) $booking->id
                    || (int) $linked->cleaning_booking_session_id !== (int) $session->id) {
                    return false;
                }
            }

            $materials = DB::table('cleaning_time_period_materials')->where('cleaning_time_period_id', $row->id)->get();
            if ($materials->count() !== count($period['materialLines'])) {
                return false;
            }
            $seenMaterials = [];
            foreach ($materials as $material) {
                $signed = collect($period['materialLines'])->first(static fn (mixed $candidate): bool => is_array($candidate)
                    && (int) ($candidate['materialTypeId'] ?? 0) === (int) $material->cleaning_material_type_id);
                if ($signed === null || isset($seenMaterials[$material->cleaning_material_type_id])
                    || ! is_numeric($signed['quantity'] ?? null)
                    || abs((float) $signed['quantity'] - (float) $material->quantity) > 0.0005
                    || (string) ($signed['unit'] ?? '') !== (string) $material->unit
                    || ! $this->sameValue($signed, $this->decodeSnapshot($material->snapshot))) {
                    return false;
                }
                $seenMaterials[$material->cleaning_material_type_id] = true;
            }

            $services = DB::table('cleaning_time_period_services as line')
                ->join('cleaning_room_schedules as source', 'source.id', '=', 'line.cleaning_room_schedule_id')
                ->join('cleaning_booking_rooms as room', 'room.id', '=', 'source.cleaning_booking_room_id')
                ->where('line.cleaning_time_period_id', $row->id)
                ->get(['line.*', 'source.schedule_key', 'source.cleaning_booking_id', 'source.cleaning_booking_session_id', 'room.client_key', 'room.cleaning_booking_id as room_booking_id']);
            if ($services->count() !== count($period['serviceLines'])) {
                return false;
            }
            $seenServices = [];
            foreach ($services as $line) {
                $signed = $this->decodeSnapshot($line->snapshot);
                if ($signed === null || ! in_array($signed, $period['serviceLines'], true)
                    || isset($seenServices[$line->service_key.'|'.$line->schedule_key])
                    || (int) $line->cleaning_booking_id !== (int) $booking->id
                    || (int) $line->cleaning_booking_session_id !== (int) $session->id
                    || (int) $line->room_booking_id !== (int) $booking->id
                    || (string) $line->client_key !== (string) ($signed['targetRoomKey'] ?? '')
                    || (string) $line->service_key !== (string) ($signed['serviceKey'] ?? '')
                    || (int) $line->cleaning_special_service_id !== (int) ($signed['specialServiceId'] ?? 0)
                    || (string) $line->dirtiness_level !== (string) ($signed['dirtinessLevel'] ?? '')
                    || (string) $line->unit !== (string) ($signed['unit'] ?? '')
                    || ! is_numeric($signed['quantity'] ?? null)
                    || abs((float) $signed['quantity'] - (float) $line->quantity) > 0.0005) {
                    return false;
                }
                $item = DB::table('cleaning_booking_special_service_items as item')
                    ->join('cleaning_booking_special_services as parent', 'parent.id', '=', 'item.cleaning_booking_special_service_id')
                    ->where('item.id', $line->cleaning_booking_special_service_item_id)
                    ->first(['item.quantity', 'item.dirtiness_level', 'item.cleaning_dirtiness_level_id', 'item.base_unit_price', 'item.price_multiplier', 'item.total_price', 'parent.cleaning_booking_id', 'parent.cleaning_special_service_id']);
                $signedItem = $signed['quoteLine']['items'][0] ?? null;
                if ($item === null || (int) $item->cleaning_booking_id !== (int) $booking->id
                    || (int) $item->cleaning_special_service_id !== (int) $line->cleaning_special_service_id
                    || ! is_array($signedItem)
                    || ! is_numeric($signedItem['quantity'] ?? null)
                    || abs((float) $item->quantity - (float) $signedItem['quantity']) > 0.0005
                    || (string) $item->dirtiness_level !== (string) ($signedItem['dirtinessLevel'] ?? '')
                    || (int) $item->cleaning_dirtiness_level_id !== (int) ($signedItem['dirtinessLevelId'] ?? 0)
                    || ! $this->moneyEquals($item->base_unit_price, $signedItem['baseUnitPrice'] ?? null)
                    || ! is_numeric($signedItem['priceMultiplier'] ?? null)
                    || abs((float) $item->price_multiplier - (float) $signedItem['priceMultiplier']) > 0.0005
                    || ! $this->moneyEquals($item->total_price, $signedItem['totalPrice'] ?? null)) {
                    return false;
                }
                $seenServices[$line->service_key.'|'.$line->schedule_key] = true;
            }
        }

        return true;
    }

    private function sessionFinancialsMatch(CleaningBookingSession $session, array $financial): bool
    {
        foreach ([
            'basePrice' => 'base_price',
            'addonsTotal' => 'addons_total',
            'materialsTotal' => 'materials_total',
            'specialServicesTotal' => 'special_services_total',
            'travelFee' => 'travel_fee',
            'adminMargin' => 'admin_margin_amount',
            'totalPrice' => 'total_price',
        ] as $key => $column) {
            if (! array_key_exists($key, $financial) || ! $this->moneyEquals($financial[$key], $session->{$column})) {
                return false;
            }
        }

        return $this->moneyEquals($financial['basePrice'], $session->base_price)
            && $this->moneyEquals($financial['addonsTotal'], $session->addons_total)
            && $this->moneyEquals(
                (float) $financial['materialsTotal'] + (float) $financial['specialServicesTotal'],
                $financial['addonsTotal'],
            )
            && $this->moneyEquals($financial['totalPrice'], $session->total_price);
    }

    private function sessionAnchorMatches(CleaningBookingSession $session, array $signedSchedules): bool
    {
        if ($signedSchedules === []) {
            return false;
        }
        $localStarts = [];
        foreach ($signedSchedules as $schedule) {
            $utc = $this->parseSignedIso($schedule['startAt'] ?? null);
            if ($utc === null) {
                return false;
            }
            $localStarts[] = $utc->setTimezone(new DateTimeZone('Asia/Damascus'));
        }
        usort($localStarts, static fn (DateTimeImmutable $a, DateTimeImmutable $b): int => $a <=> $b);
        $anchor = $localStarts[0];
        $date = $session->getRawOriginal('scheduled_date');
        $time = $session->getRawOriginal('scheduled_time');
        if (is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2} 00:00:00$/', $date)) {
            $date = mb_substr($date, 0, 10);
        }
        if (is_string($time) && preg_match('/^\d{2}:\d{2}:\d{2}$/', $time)) {
            $time = mb_substr($time, 0, 5);
        }
        $parsed = $this->parseLocalDateTime(is_string($date) && is_string($time) ? $date.' '.$time : null);

        return $parsed !== null && $parsed->format(self::LOCAL_FORMAT) === $anchor->format(self::LOCAL_FORMAT);
    }

    private function sameSignedInstant(mixed $value, DateTimeImmutable $rawUtc): bool
    {
        $signed = $this->parseSignedIso($value);

        return $signed !== null && $signed->getTimestamp() === $rawUtc->getTimestamp();
    }

    private function parseUtcDatabaseValue(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!'.self::UTC_FORMAT, $value, new DateTimeZone('UTC'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format(self::UTC_FORMAT) !== $value) {
            return null;
        }

        return $parsed;
    }

    private function parseSignedIso(mixed $value): ?DateTimeImmutable
    {
        if (! is_string($value)) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!'.self::ISO_FORMAT, $value);
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format(self::ISO_FORMAT) !== $value) {
            return null;
        }

        return $parsed->setTimezone(new DateTimeZone('UTC'));
    }

    private function parseLocalDateTime(?string $value): ?DateTimeImmutable
    {
        if ($value === null) {
            return null;
        }
        $parsed = DateTimeImmutable::createFromFormat('!'.self::LOCAL_FORMAT, $value, new DateTimeZone('Asia/Damascus'));
        $errors = DateTimeImmutable::getLastErrors();
        if ($parsed === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))
            || $parsed->format(self::LOCAL_FORMAT) !== $value) {
            return null;
        }

        return $parsed;
    }

    private function decodeSnapshot(mixed $raw): ?array
    {
        if (! is_string($raw) || $raw === '') {
            return null;
        }
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return null;
        }

        return is_array($decoded) && ! array_is_list($decoded) ? $decoded : null;
    }

    private function moneyEquals(mixed $left, mixed $right): bool
    {
        $toMinor = static function (mixed $value): ?int {
            if (! is_numeric($value) || ! is_finite((float) $value)) {
                return null;
            }
            $scaled = (float) $value * 100;
            if (! is_finite($scaled) || abs($scaled - round($scaled)) > 0.00001) {
                return null;
            }

            return (int) round($scaled);
        };
        $a = $toMinor($left);
        $b = $toMinor($right);

        return $a !== null && $b !== null && $a === $b;
    }

    private function isInteger(mixed $value): bool
    {
        return (is_int($value) || (is_string($value) && ctype_digit($value))) && (int) $value >= 0;
    }

    private function sameValue(mixed $left, mixed $right): bool
    {
        return json_encode($this->canonical($left), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)
            === json_encode($this->canonical($right), JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (! array_is_list($value)) {
            ksort($value);
        }

        foreach ($value as $key => $item) {
            $value[$key] = $this->canonical($item);
        }

        return $value;
    }

    private function uniqueByInt(array $items, string $key, int $value): ?array
    {
        $matches = array_values(array_filter($items, static fn (mixed $item): bool => is_array($item) && (int) ($item[$key] ?? 0) === $value));

        return count($matches) === 1 ? $matches[0] : null;
    }

    private function value(mixed $value): string
    {
        return is_object($value) && property_exists($value, 'value') ? (string) $value->value : (string) $value;
    }

    /** @return array{valid:false,reasonCodes:list<string>,evidenceDigest:null,taskCandidates:array{}} */
    private function invalid(string $reason): array
    {
        return ['valid' => false, 'reasonCodes' => [$reason], 'evidenceDigest' => null, 'taskCandidates' => []];
    }
}
