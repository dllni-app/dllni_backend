<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\User;
use App\Models\Worker;
use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

/**
 * Live extension of an explicitly paused visit. Customer confirms the exact
 * signed price; the current responsible worker/supervisor accepts; only an
 * independently signed treasury reservation can apply the new price/time cap.
 */
final class CleaningV3LiveExtensionService
{
    private const TABLE = 'cleaning_v3_live_extension_quotes';

    public function quote(int $bookingId, int $visitId, User $actor, int $minutes, int $priceRevision, int $clockRevision, string $command): array
    {
        return DB::transaction(function () use ($bookingId, $visitId, $actor, $minutes, $priceRevision, $clockRevision, $command) {
            [$booking, $visit] = $this->lock($bookingId, $visitId);
            abort_unless(app(CleaningV3WorkClockAccessPolicy::class)->isCustomer($actor, $booking), 404);
            if (! preg_match('/^[a-zA-Z0-9_.:-]{1,96}$/D', $command)) {
                throw new DomainException('V3_EXTENSION_COMMAND_INVALID');
            }
            $prior = DB::table(self::TABLE)->where('cleaning_booking_session_id', $visitId)->where('command_key', $command)->lockForUpdate()->first();
            if ($prior !== null) {
                $p = $this->payload($prior);
                if ($p['previousRevision'] !== $priceRevision || $p['clockRevision'] !== $clockRevision || $p['price']['additionalMinutes'] !== $minutes) {
                    throw new DomainException('V3_EXTENSION_COMMAND_CONFLICT');
                }
                return $this->present($prior);
            }
            $this->assertPaused($booking, $visit, $priceRevision, $clockRevision);
            [$quote, $snapshot, $row, $basis, $revision] = $this->effectivePrice($booking, $visit);
            app(CleaningV3ConfirmedVisitPrice::class)->assertPersisted($visit, $row, $basis, $revision);
            $digest = app(CleaningV3LiveExtensionPricing::class)->digest($quote->id, $bookingId, $visitId, $revision, $row, $basis);
            app(CleaningV3VerifiedTreasuryReservationService::class)->assertVerified($booking, $visit, $quote->id, $revision, $digest,
                app(CleaningV3ConfirmedVisitPrice::class)->obligations($snapshot, $row, $basis));
            $price = app(CleaningV3LiveExtensionPricing::class)->revise($snapshot, $visit->occurrence_key, $minutes);
            $clock = app(CleaningV3WorkClockProjectionService::class)->project($visit);
            $this->assertWindow($visit, $price['newMinutes'], $clock['activeSeconds']);
            // No simultaneous outstanding offers on this visit. Older offers
            // are retained and can never be applied after supersession.
            $outstanding = DB::table(self::TABLE)->where('cleaning_booking_session_id', $visitId)
                ->whereIn('status', ['offered', 'customer_confirmed', 'crew_approved'])->lockForUpdate()->get();
            foreach ($outstanding as $other) {
                if (CarbonImmutable::parse($other->expires_at, 'UTC')->isFuture() && $other->status !== 'offered') {
                    throw new DomainException('V3_EXTENSION_ALREADY_PENDING');
                }
                DB::table(self::TABLE)->where('id', $other->id)->update(['status' => 'superseded', 'updated_at' => now()]);
            }
            $at = CarbonImmutable::now('UTC')->startOfSecond();
            $id = (string) Str::uuid();
            $p = [
                'extensionId' => $id, 'originalQuoteId' => $quote->id, 'bookingId' => $bookingId, 'visitId' => $visitId,
                'customerId' => (int) $actor->id, 'previousRevision' => $revision, 'nextRevision' => $revision + 1,
                'clockRevision' => $clockRevision, 'rosterDigest' => $this->rosterDigest($booking, $visit),
                'price' => $price, 'issuedAt' => $at->toDateTimeString(), 'expiresAt' => $at->addMinutes(15)->toDateTimeString(),
            ];
            DB::table(self::TABLE)->insert([
                'id' => $id, 'cleaning_booking_session_id' => $visitId, 'original_quote_id' => $quote->id,
                'customer_id' => $actor->id, 'command_key' => $command, 'previous_revision' => $revision,
                'next_revision' => $revision + 1, 'clock_revision' => $clockRevision, 'status' => 'offered',
                'payload' => json_encode($p, JSON_THROW_ON_ERROR), 'signature' => $this->mac('offer', $p),
                'expires_at' => $p['expiresAt'], 'created_at' => $at, 'updated_at' => $at,
            ]);
            return $this->present($this->find($visitId, $id));
        }, 3);
    }

    public function current(int $bookingId, int $visitId, User $actor): ?array
    {
        return DB::transaction(function () use ($bookingId, $visitId, $actor) {
            [$booking, $visit] = $this->lock($bookingId, $visitId);
            $access = app(CleaningV3WorkClockAccessPolicy::class);
            $customer = $access->isCustomer($actor, $booking);
            abort_unless($customer || $access->canDecide($actor, $booking, $visit), 404);
            $query = DB::table(self::TABLE)->where('cleaning_booking_session_id', $visitId)
                ->whereIn('status', $customer ? ['offered', 'customer_confirmed', 'crew_approved', 'applied'] : ['customer_confirmed', 'crew_approved', 'applied']);
            $row = $query->orderByDesc('created_at')->orderByDesc('next_revision')->first();
            return $row === null ? null : $this->present($row);
        }, 3);
    }

    public function confirm(int $bookingId, int $visitId, string $id, User $actor, string $signature, string $total): array
    {
        return DB::transaction(function () use ($bookingId, $visitId, $id, $actor, $signature, $total) {
            [$booking, $visit] = $this->lock($bookingId, $visitId);
            abort_unless(app(CleaningV3WorkClockAccessPolicy::class)->isCustomer($actor, $booking), 404);
            $row = $this->find($visitId, $id);
            $p = $this->payload($row);
            if (! hash_equals($row->signature, $signature) || $p['price']['newTotalSyp'] !== $total) {
                throw new DomainException('V3_EXTENSION_PRICE_CONFIRMATION_MISMATCH');
            }
            if (in_array($row->status, ['customer_confirmed', 'crew_approved', 'applied'], true)) {
                $this->assertCustomerConsent($row);
                return $this->present($row);
            }
            $this->assertCurrent($row, $p, $booking, $visit, 'offered');
            $at = CarbonImmutable::now('UTC')->toDateTimeString();
            DB::table(self::TABLE)->where('id', $id)->update([
                'status' => 'customer_confirmed', 'customer_confirmed_at' => $at,
                'customer_confirmation_mac' => $this->mac('customer', [$row->signature, (int) $actor->id, $at]), 'updated_at' => now(),
            ]);
            return $this->present($this->find($visitId, $id));
        }, 3);
    }

    public function decide(int $bookingId, int $visitId, string $id, User $actor, string $decision): array
    {
        return DB::transaction(function () use ($bookingId, $visitId, $id, $actor, $decision) {
            [$booking, $visit] = $this->lock($bookingId, $visitId);
            abort_unless(app(CleaningV3WorkClockAccessPolicy::class)->canDecide($actor, $booking, $visit), 404);
            $row = $this->find($visitId, $id);
            $p = $this->payload($row);
            $status = match ($decision) {
                'approve' => 'crew_approved', 'reject' => 'rejected',
                default => throw new DomainException('V3_EXTENSION_DECISION_INVALID'),
            };
            if (($row->status === $status || ($row->status === 'applied' && $status === 'crew_approved')) && (int) $row->decided_by === (int) $actor->id) {
                $this->assertCrewConsent($row, $status);
                return $this->present($row);
            }
            $this->assertCurrent($row, $p, $booking, $visit, 'customer_confirmed');
            $this->assertCustomerConsent($row);
            $at = CarbonImmutable::now('UTC')->toDateTimeString();
            DB::table(self::TABLE)->where('id', $id)->update([
                'status' => $status, 'decided_at' => $at, 'decided_by' => $actor->id,
                'decision_mac' => $this->mac('crew', [$row->customer_confirmation_mac, (int) $actor->id, $status, $at]),
                'updated_at' => now(),
            ]);
            return $this->present($this->find($visitId, $id));
        }, 3);
    }

    /** Exact revised liabilities for the independent custodian, never a funding grant. */
    public function fundingRequirement(int $bookingId, int $visitId, string $id): array
    {
        abort_unless(CleaningV3CashSettlementService::authorized(), 403);
        return DB::transaction(function () use ($bookingId, $visitId, $id) {
            [$booking, $visit] = $this->lock($bookingId, $visitId);
            $row = $this->find($visitId, $id);
            $p = $this->payload($row);
            $this->assertCurrent($row, $p, $booking, $visit, 'crew_approved');
            $this->assertCustomerConsent($row);
            $this->assertCrewConsent($row, 'crew_approved');
            [$original, $snapshot, $currentRow, $currentBasis, $revision] = $this->effectivePrice($booking, $visit);
            app(CleaningV3ConfirmedVisitPrice::class)->assertPersisted($visit, $currentRow, $currentBasis, $revision);
            $pricing = app(CleaningV3LiveExtensionPricing::class);
            $price = $pricing->revise($snapshot, $visit->occurrence_key, $p['price']['additionalMinutes']);
            if ($original->id !== $p['originalQuoteId'] || $price != $p['price']) {
                throw new DomainException('V3_EXTENSION_PRICE_CHANGED');
            }
            app(CleaningV3VerifiedTreasuryReservationService::class)->assertVerified($booking, $visit, $original->id, $revision,
                $pricing->digest($original->id, $bookingId, $visitId, $revision, $currentRow, $currentBasis),
                app(CleaningV3ConfirmedVisitPrice::class)->obligations($snapshot, $currentRow, $currentBasis));
            $active = DB::table('cleaning_visit_funding_reservations')->where('active_visit_id', $visitId)->lockForUpdate()->first();
            return ['bookingId' => $bookingId, 'visitId' => $visitId, 'quoteId' => $original->id,
                'pricingRevision' => $revision + 1, 'supersedesAllocationId' => $active->reservation_key,
                'pricingDigest' => $pricing->digest($original->id, $bookingId, $visitId, $revision + 1, $price['row'], $price['basis']),
                ...array_intersect_key($price, array_flip(['workerObligationSyp', 'supervisorObligationSyp', 'requiredFundingSyp']))];
        }, 3);
    }

    /** Only an authenticated treasury importer can submit the external proof. */
    public function apply(int $bookingId, int $visitId, string $id, array $proof): array
    {
        abort_unless(CleaningV3CashSettlementService::authorized(), 403);
        return DB::transaction(function () use ($bookingId, $visitId, $id, $proof) {
            [$booking, $visit] = $this->lock($bookingId, $visitId);
            $row = $this->find($visitId, $id);
            $p = $this->payload($row);
            if ($row->status === 'applied') {
                $this->assertApplied($row);
                return $this->present($row);
            }
            $this->assertCurrent($row, $p, $booking, $visit, 'crew_approved');
            $this->assertCustomerConsent($row);
            $this->assertCrewConsent($row, 'crew_approved');
            $decider = User::query()->whereKey($row->decided_by)->first();
            abort_unless(app(CleaningV3WorkClockAccessPolicy::class)->canDecide($decider, $booking, $visit), 409, 'V3_EXTENSION_CREW_CHANGED');
            [$original, $snapshot, $currentRow, $currentBasis, $revision] = $this->effectivePrice($booking, $visit);
            app(CleaningV3ConfirmedVisitPrice::class)->assertPersisted($visit, $currentRow, $currentBasis, $revision);
            $pricing = app(CleaningV3LiveExtensionPricing::class);
            $price = $pricing->revise($snapshot, $visit->occurrence_key, $p['price']['additionalMinutes']);
            if ($original->id !== $p['originalQuoteId'] || $price != $p['price']) {
                throw new DomainException('V3_EXTENSION_PRICE_CHANGED');
            }
            $clock = app(CleaningV3WorkClockProjectionService::class)->project($visit);
            $end = $this->assertWindow($visit, $price['newMinutes'], $clock['activeSeconds']);
            app(CleaningV3EquipmentResumeGuard::class)->extend($visit, $end);
            $oldDigest = $pricing->digest($original->id, $bookingId, $visitId, $revision, $currentRow, $currentBasis);
            $funding = app(CleaningV3VerifiedTreasuryReservationService::class);
            $funding->assertVerified($booking, $visit, $original->id, $revision, $oldDigest,
                app(CleaningV3ConfirmedVisitPrice::class)->obligations($snapshot, $currentRow, $currentBasis));
            $obligations = array_intersect_key($price, array_flip(['workerObligationSyp', 'supervisorObligationSyp', 'requiredFundingSyp']));
            $digest = $pricing->digest($original->id, $bookingId, $visitId, $revision + 1, $price['row'], $price['basis']);
            $funding->reserve($booking, $visit, $original->id, $revision + 1, $digest, $obligations, $proof, (int) auth()->id());
            $allocation = app(CleaningV3TreasuryAllocationVerifier::class)->verify($proof);
            $at = CarbonImmutable::now('UTC')->toDateTimeString();
            $parentTotal = bcadd((string) $booking->getRawOriginal('total_price'), $price['additionalAmountSyp'], 2);
            if (bccomp($parentTotal, '99999999.99', 2) > 0 || bccomp($price['newTotalSyp'], '99999999.99', 2) > 0) {
                throw new DomainException('V3_EXTENSION_AMOUNT_LIMIT');
            }
            $visit->forceFill([
                'duration_hours' => $price['newMinutes'] / 60,
                'base_price' => $price['row']['baseAmountSyp'], 'total_price' => $price['newTotalSyp'],
                'price_revision' => $revision + 1, 'clock_revision' => (int) $visit->clock_revision + 1,
                'pricing_snapshot' => [...($visit->pricing_snapshot ?? []), 'commercialRow' => $price['row'],
                    'settlementBasis' => $price['basis'], 'liveExtensionId' => $id],
            ])->saveQuietly();
            $booking->forceFill([
                'base_price' => bcadd((string) $booking->getRawOriginal('base_price'), $price['additionalAmountSyp'], 2),
                'total_price' => $parentTotal,
                'total_hours' => (float) $booking->total_hours + $price['additionalMinutes'] / 60,
                'estimated_hours' => (float) $booking->estimated_hours + $price['additionalMinutes'] / 60,
                ...($booking->subtotal_before_discount === null ? [] : [
                    'subtotal_before_discount' => bcadd((string) $booking->getRawOriginal('subtotal_before_discount'), $price['additionalAmountSyp'], 2),
                ]),
            ])->saveQuietly();
            DB::table(self::TABLE)->where('id', $id)->update([
                'status' => 'applied', 'applied_revision' => $revision + 1, 'applied_at' => $at,
                'applied_allocation_id' => $allocation['allocationId'],
                'applied_mac' => $this->mac('applied', [$row->decision_mac, $allocation['allocationId'], $at, $revision + 1]), 'updated_at' => now(),
            ]);
            return $this->present($this->find($visitId, $id));
        }, 3);
    }

    /** Verify every accepted revision again before displaying or settling it. */
    public function effectivePrice(CleaningBooking $booking, CleaningBookingSession $visit): array
    {
        [$quote, $snapshot, $row, $basis, $revision] = app(CleaningV3ConfirmedVisitPrice::class)->load($booking, $visit);
        $history = DB::table(self::TABLE)->where('cleaning_booking_session_id', $visit->id)->whereNotNull('applied_revision')
            ->orderBy('applied_revision')->lockForUpdate()->get();
        foreach ($history as $extension) {
            $p = $this->payload($extension);
            $this->assertApplied($extension);
            if ($p['originalQuoteId'] !== $quote->id || $p['bookingId'] !== (int) $booking->id || $p['visitId'] !== (int) $visit->id
                || $p['customerId'] !== (int) $booking->customer_id || $p['previousRevision'] !== $revision || $p['nextRevision'] !== $revision + 1) {
                throw new DomainException('V3_EXTENSION_HISTORY_MISMATCH');
            }
            $price = app(CleaningV3LiveExtensionPricing::class)->revise($snapshot, $visit->occurrence_key, $p['price']['additionalMinutes']);
            if ($price != $p['price']) {
                throw new DomainException('V3_EXTENSION_HISTORY_MISMATCH');
            }
            $row = $price['row'];
            $basis = $price['basis'];
            foreach ($snapshot['commercialReview']['visits'] as &$r) {
                if ($r['occurrenceKey'] === $visit->occurrence_key) {
                    $r = $row;
                }
            }
            unset($r);
            $snapshot['settlementBasis']['visits'][$visit->occurrence_key] = $basis;
            $revision++;
        }
        // Effective snapshot is INTERNAL. The original signed quote remains
        // immutable, and every extension has its own separate HMAC/consents.
        $snapshot['pricingRevision'] = $revision;
        return [$quote, $snapshot, $row, $basis, $revision];
    }

    private function assertWindow(CleaningBookingSession $visit, int $minutes, int $activeSeconds): CarbonImmutable
    {
        $at = CarbonImmutable::now('UTC');
        $remaining = $minutes - intdiv($activeSeconds + 59, 60);
        $first = DB::table('cleaning_work_intervals')->where('cleaning_booking_session_id', $visit->id)->orderBy('started_at')->value('started_at');
        $end = $at->addMinutes(max(0, $remaining));
        if ($remaining < 1 || $first === null || CarbonImmutable::parse($first, 'UTC')->diffInSeconds($end, false) > 480 * 60) {
            throw new DomainException('V3_EXTENSION_RESCHEDULE_REQUIRED');
        }
        $workers = $visit->workerAssignments()->whereNull('released_at')->where('status', 'in_progress')
            ->whereNotNull('work_started_at')->orderBy('worker_id')->lockForUpdate()->get();
        if ($workers->count() !== $visit->requiredWorkerCount()) {
            throw new DomainException('V3_EXTENSION_CREW_CHANGED');
        }
        foreach ($workers as $assignment) {
            $worker = Worker::query()->whereKey($assignment->worker_id)->lockForUpdate()->firstOrFail();
            if (app(WorkerBookingScheduleConflictService::class)->hasConflictForV3ResumeWindow($worker, $visit, $at, $end)) {
                throw new DomainException('V3_EXTENSION_RESCHEDULE_REQUIRED');
            }
        }
        return $end;
    }

    private function lock(int $bookingId, int $visitId): array
    {
        $booking = CleaningBooking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail();
        abort_unless($booking->contract_version === 'v3', 404);
        $visit = $booking->sessions()->whereKey($visitId)->lockForUpdate()->firstOrFail();
        $visit->setRelation('booking', $booking);
        return [$booking, $visit];
    }

    private function assertPaused(CleaningBooking $booking, CleaningBookingSession $visit, int $revision, int $clockRevision): void
    {
        if (data_get($booking->property_details, 'v3_admission_hold') !== false
            || $visit->status !== CleaningBookingSessionStatus::Paused || (int) $visit->price_revision !== $revision
            || (int) $visit->clock_revision !== $clockRevision || $visit->payment_status === 'settled'
            || app(CleaningV3WorkClockProjectionService::class)->project($visit)['state'] !== 'paused'
            || DB::table('cleaning_v3_cash_movements')->where('cleaning_booking_session_id', $visit->id)->exists()) {
            throw new DomainException('V3_EXTENSION_REQUIRES_PAUSED_ADMITTED_VISIT');
        }
    }

    private function assertCurrent(object $row, array $p, CleaningBooking $booking, CleaningBookingSession $visit, string $status): void
    {
        $this->assertPaused($booking, $visit, $p['previousRevision'], $p['clockRevision']);
        if ($row->status !== $status || CarbonImmutable::parse($p['expiresAt'], 'UTC')->lessThanOrEqualTo(CarbonImmutable::now('UTC'))
            || $p['bookingId'] !== (int) $booking->id || $p['customerId'] !== (int) $booking->customer_id
            || ! hash_equals($p['rosterDigest'], $this->rosterDigest($booking, $visit))) {
            throw new DomainException('V3_EXTENSION_STALE_OR_EXPIRED');
        }
    }

    private function rosterDigest(CleaningBooking $booking, CleaningBookingSession $visit): string
    {
        $workers = $visit->workerAssignments()->whereNull('released_at')->where('status', 'in_progress')
            ->orderBy('worker_id')->lockForUpdate()->get(['id', 'worker_id', 'accepted_at']);
        $supervisor = $booking->supervisorAssignments()->whereNull('released_at')->where('active_slot', 1)
            ->lockForUpdate()->get(['id', 'supervisor_user_id']);
        return hash('sha256', json_encode([$workers->toArray(), $supervisor->toArray()], JSON_THROW_ON_ERROR));
    }

    private function find(int $visit, string $id): object
    {
        $row = DB::table(self::TABLE)->where('cleaning_booking_session_id', $visit)->where('id', $id)->lockForUpdate()->first();
        abort_if($row === null, 404);
        return $row;
    }

    private function payload(object $row): array
    {
        $p = json_decode((string) $row->payload, true);
        if (! is_array($p) || ! hash_equals($this->mac('offer', $p), $row->signature)
            || $p['extensionId'] !== $row->id || $p['originalQuoteId'] !== $row->original_quote_id
            || $p['visitId'] !== (int) $row->cleaning_booking_session_id || $p['customerId'] !== (int) $row->customer_id
            || $p['previousRevision'] !== (int) $row->previous_revision || $p['nextRevision'] !== (int) $row->next_revision
            || $p['clockRevision'] !== (int) $row->clock_revision || $p['expiresAt'] !== (string) $row->expires_at) {
            throw new DomainException('V3_EXTENSION_SIGNATURE_INVALID');
        }
        return $p;
    }

    private function assertCustomerConsent(object $row): void
    {
        if ($row->customer_confirmed_at === null || ! hash_equals((string) $row->customer_confirmation_mac,
            $this->mac('customer', [$row->signature, (int) $row->customer_id, (string) $row->customer_confirmed_at]))) {
            throw new DomainException('V3_EXTENSION_CUSTOMER_CONSENT_REQUIRED');
        }
    }

    private function assertCrewConsent(object $row, string $status): void
    {
        if ($row->decided_by === null || $row->decided_at === null || ! hash_equals((string) $row->decision_mac,
            $this->mac('crew', [$row->customer_confirmation_mac, (int) $row->decided_by, $status, (string) $row->decided_at]))) {
            throw new DomainException('V3_EXTENSION_CREW_CONSENT_REQUIRED');
        }
    }

    private function assertApplied(object $row): void
    {
        $this->assertCustomerConsent($row);
        $this->assertCrewConsent($row, 'crew_approved');
        if ($row->status !== 'applied' || $row->applied_at === null || $row->applied_allocation_id === null
            || (int) $row->applied_revision !== (int) $row->next_revision || ! hash_equals((string) $row->applied_mac,
                $this->mac('applied', [$row->decision_mac, $row->applied_allocation_id, (string) $row->applied_at, (int) $row->applied_revision]))) {
            throw new DomainException('V3_EXTENSION_HISTORY_MISMATCH');
        }
    }

    private function present(object $row): array
    {
        $p = $this->payload($row);
        $status = $row->status;
        if (in_array($status, ['offered', 'customer_confirmed', 'crew_approved'], true) && CarbonImmutable::parse($row->expires_at, 'UTC')->isPast()) {
            $status = 'expired';
        }
        return ['extensionId' => $row->id, 'status' => $status, 'signature' => $row->signature,
            'previousRevision' => $p['previousRevision'], 'nextRevision' => $p['nextRevision'], 'clockRevision' => $p['clockRevision'],
            'expiresAt' => CarbonImmutable::parse($p['expiresAt'], 'UTC')->toIso8601String(),
            ...array_intersect_key($p['price'], array_flip(['oldMinutes', 'newMinutes', 'additionalMinutes', 'oldTotalSyp',
                'newTotalSyp', 'additionalAmountSyp', 'additionalWorkerFeeSyp', 'discountAmountSyp'])),
        ];
    }

    private function mac(string $domain, array $data): string
    {
        $key = config('app.key');
        if (is_string($key) && str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7), true);
        }
        if (! is_string($key) || strlen($key) < 32) {
            throw new DomainException('V3_EXTENSION_SIGNING_KEY_REQUIRED');
        }
        $canonical = function ($item) use (&$canonical) {
            if (! is_array($item)) {
                return $item;
            }
            if (! array_is_list($item)) {
                ksort($item);
            }
            return array_map($canonical, $item);
        };
        return hash_hmac('sha256', json_encode(['v3-live-extension-v1', $domain, $canonical($data)], JSON_THROW_ON_ERROR), $key);
    }
}
