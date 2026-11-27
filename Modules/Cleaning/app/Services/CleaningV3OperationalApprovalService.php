<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;

/** New approvals only; never interprets a quote or approval as a cash receipt. */
final class CleaningV3OperationalApprovalService
{
    private const TABLE = 'cleaning_v3_operational_approvals';

    public function approve(int $bookingId): array
    {
        abort_unless(CleaningV3CashSettlementService::authorized(), 403);

        return DB::transaction(function () use ($bookingId): array {
            // All competing approval/revision/cancellation commands serialize
            // on the booking before accessing its child visits or quote.
            $booking = CleaningBooking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail();
            abort_unless($booking->contract_version === 'v3', 404);
            $prior = DB::table(self::TABLE)->where('booking_id', $bookingId)->lockForUpdate()->first();
            if ($prior !== null) {
                if (! app(CleaningV3OperationalAdmissionPolicy::class)->approved($booking)) {
                    throw new DomainException('V3_OPERATIONAL_APPROVAL_RECONCILIATION_REQUIRED');
                }
                // A replay reports the original decision, not a new release
                // or a promise that every original visit is still available.
                return $this->result($bookingId, $prior, true);
            }

            if (! config('cleaning_v3_catalog.operational_approval_enabled', false)) {
                return ['bookingId' => $bookingId, 'approved' => false, 'replayed' => false,
                    'blockers' => ['V3_OPERATIONAL_APPROVAL_DISABLED']];
            }

            // Re-read inside the SAME transaction; never accept a stale
            // browser review or a caller-supplied funding/rollout assertion.
            $review = app(CleaningV3OperationalAdmissionReview::class)->review($bookingId);
            if (! $review['canDispatch'] || $review['blockers'] !== []) {
                return ['bookingId' => $bookingId, 'approved' => false, 'replayed' => false,
                    'blockers' => $review['blockers']];
            }

            $visits = $booking->sessions()->orderBy('sequence')->lockForUpdate()->get();
            $quoteId = data_get($booking->property_details, 'v3_current_quote_id');
            $revision = (int) $visits->first()?->price_revision;
            if (! is_string($quoteId) || $revision < 1 || $visits->isEmpty()
                || $visits->count() !== $review['visitCount']
                || count($review['fundingRequests']) !== $visits->count()
                || $visits->contains(fn ($visit) => (int) $visit->price_revision !== $revision)
                || collect($review['fundingRequests'])->contains(fn ($request) =>
                    ($request['quoteId'] ?? null) !== $quoteId || ($request['pricingRevision'] ?? null) !== $revision)) {
                throw new DomainException('V3_OPERATIONAL_APPROVAL_RECONCILIATION_REQUIRED');
            }

            $now = CarbonImmutable::now('UTC');
            $visitIds = $visits->map(fn ($visit) => (int) $visit->id)->all();
            DB::table(self::TABLE)->insert([
                'booking_id' => $bookingId, 'quote_id' => $quoteId,
                'approved_by' => (int) auth()->id(), 'price_revision' => $revision,
                'visit_ids' => json_encode($visitIds, JSON_THROW_ON_ERROR), 'approved_at' => $now,
            ]);

            $details = $booking->property_details;
            $details['v3_admission_hold'] = false;
            // Avoid legacy booking-level pricing, commission and notifications.
            $booking->forceFill(['property_details' => $details, 'search_started_at' => $now])->saveQuietly();
            foreach ($visits as $visit) {
                app(CleaningV3VisitDispatchService::class)->openSearch($visit, $now);
            }

            $record = DB::table(self::TABLE)->where('booking_id', $bookingId)->first();
            return $this->result($bookingId, $record, false);
        }, 3);
    }

    private function result(int $bookingId, object $record, bool $replayed): array
    {
        return ['bookingId' => $bookingId, 'approved' => true, 'replayed' => $replayed,
            'quoteId' => $record->quote_id, 'priceRevision' => (int) $record->price_revision,
            'visitIds' => json_decode($record->visit_ids, true, flags: JSON_THROW_ON_ERROR),
            'approvedAt' => CarbonImmutable::parse($record->approved_at, 'UTC')->toIso8601String(),
            'blockers' => []];
    }
}
