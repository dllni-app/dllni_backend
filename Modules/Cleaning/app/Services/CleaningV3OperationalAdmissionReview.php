<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/** Read-only diagnosis; a review result cannot create cleared funds or open dispatch. */
final class CleaningV3OperationalAdmissionReview
{
    public function review(int $bookingId): array
    {
        abort_unless(CleaningV3CashSettlementService::authorized(), 403);
        return $this->inspect($bookingId);
    }

    /** Internal preflight; callers cannot use the public review to bypass authorization. */
    public function inspect(int $bookingId): array
    {
        return DB::transaction(function () use ($bookingId) {
            $booking = CleaningBooking::query()->whereKey($bookingId)->lockForUpdate()->firstOrFail();
            abort_unless($booking->contract_version === 'v3', 404);
            $visits = $booking->sessions()->orderBy('sequence')->lockForUpdate()->get();
            $issues = [];
            if ($booking->status->value !== 'pending' || data_get($booking->property_details, 'v3_admission_hold') !== true) {
                $issues[] = 'BOOKING_NOT_PENDING_OPERATIONAL_REVIEW';
            }
            $catalog = CleaningV3CatalogVersion::query()->whereKey($booking->catalog_version_id)->first();
            $query = DB::table('cleaning_booking_quotes')->where('cleaning_booking_id', $booking->id)->where('contract_version', 'v3');
            if (is_string($current = data_get($booking->property_details, 'v3_current_quote_id'))) {
                $query->where('id', $current);
            }
            $quotes = $query->lockForUpdate()->get();
            $valid = false;
            if ($catalog !== null && $catalog->status === 'published' && $quotes->count() === 1) {
                $quote = $quotes->first();
                $envelope = json_decode((string) $quote->snapshot, true);
                try {
                    if (! is_array($envelope) || ($envelope['quoteId'] ?? null) !== $quote->id
                        || ! hash_equals((string) $quote->signature, (string) ($envelope['signature'] ?? ''))
                        || (int) $quote->catalog_version_id !== (int) $catalog->id
                        || (int) $quote->customer_id !== (int) $booking->customer_id
                        || ! hash_equals((string) $catalog->published_digest, app(CleaningV3CatalogReviewManifestService::class)->fingerprint($catalog))) {
                        throw new DomainException('INVALID_QUOTE');
                    }
                    $accepted = data_get($booking->property_details, 'v3_quote_accepted_at');
                    $at = is_string($accepted) ? CarbonImmutable::parse($accepted)->utc() : CarbonImmutable::instance($booking->created_at)->utc();
                    $snapshot = app(CleaningV3SignedQuoteEnvelope::class)->verify($envelope, (int) $booking->customer_id,
                        $catalog->version_key, $catalog->published_digest, $at);
                    $rows = collect($snapshot['commercialReview']['visits'] ?? [])->keyBy('occurrenceKey');
                    $valid = ($snapshot['paymentStatus'] ?? null) === 'unpaid' && $rows->count() === $visits->count()
                        && $visits->isNotEmpty() && bccomp($snapshot['totalWithAdditionalWorkerFees'], (string) $booking->getRawOriginal('total_price'), 2) === 0;
                    $draft = json_decode((string) $quote->v3_confirmed_draft, true);
                    $prefs = app(CleaningV3CustomerPreferences::class)->resolve((int) $booking->customer_id, $draft['preferences'] ?? null, true);
                    $valid = $valid && (int) data_get($booking->property_details, 'v3_address_id') === (int) $prefs['address']['addressId']
                        && abs((float) $booking->address_latitude - (float) $prefs['address']['latitude']) < 0.000001
                        && abs((float) $booking->address_longitude - (float) $prefs['address']['longitude']) < 0.000001
                        && ($booking->gender_preference?->value ?? 'any') === $prefs['genderPreference']
                        && data_get($booking->property_details, 'v3_excluded_worker_ids') === $prefs['excludedWorkerIds'];
                    $confirmed = collect($draft['appointments'] ?? [])->flatMap(fn ($a) => collect($a['visits'] ?? [])
                        ->map(fn ($v) => [...$v, 'appointmentKey' => $a['appointmentKey']]))->keyBy('occurrenceKey');
                    $valid = $valid && $confirmed->count() === $visits->count();
                    foreach ($visits as $visit) {
                        $plan = $confirmed->get($visit->occurrence_key);
                        $valid = $valid && $plan !== null
                            && $plan['appointmentKey'] === $visit->appointment_key
                            && $plan['scheduledDate'] === $visit->scheduled_date?->toDateString()
                            && $plan['scheduledTime'] === mb_substr((string) $visit->scheduled_time, 0, 5)
                            && $plan['durationMinutes'] === (int) round($visit->duration_hours * 60)
                            && $plan['requiredWorkers'] === $visit->requiredWorkerCount();
                        $row = $rows->get($visit->occurrence_key);
                        $valid = $valid && $row !== null && $row['appointmentKey'] === $visit->appointment_key
                            && $row['pricedWorkers'] === $visit->requiredWorkerCount()
                            && (int) round((float) $visit->duration_hours * 60) === ($snapshot['settlementBasis']['visits'][$visit->occurrence_key]['plannedMinutes'] ?? null)
                            && $snapshot['pricingRevision'] === (int) $visit->price_revision
                            && bccomp($row['totalAmountSyp'], (string) $visit->getRawOriginal('total_price'), 2) === 0;
                    }
                } catch (DomainException) {
                    $valid = false;
                }
            }
            if (! $valid) {
                $issues[] = 'SIGNED_PRICE_OR_VISIT_MISMATCH';
            }
            if ($booking->workerAssignments()->exists() || $booking->address_latitude === null || $booking->address_longitude === null) {
                $issues[] = 'VISIT_SCHEDULE_OR_ROSTER_REVIEW_REQUIRED';
            }
            if ($visits->isEmpty() || $visits->contains(fn ($v) => $v->status->value !== 'scheduled'
                || $v->startsAt() === null || $v->startsAt()->isPast() || $v->workerAssignments()->exists())) {
                $issues[] = 'VISIT_SCHEDULE_OR_ROSTER_REVIEW_REQUIRED';
            }
            try {
                app(CleaningWorkerApkUpdateManifestService::class)->manifest();
            } catch (DomainException) {
                $issues[] = 'WORKER_APK_MANIFEST_UNAVAILABLE';
            }
            $fundingRequests = [];
            $fundingVerified = $valid && $visits->isNotEmpty();
            $paymentPolicyReady = $fundingVerified;
            foreach ($visits as $visit) {
                try {
                    $request = app(CleaningV3OperationalFundingService::class)->requirement($booking, $visit);
                    $fundingRequests[] = $request;
                    $fundingVerified = $fundingVerified && $request['verified'];
                    $paymentPolicyReady = $paymentPolicyReady && ($request['verified'] || ($request['cashPricePayable'] ?? false));
                } catch (DomainException $e) {
                    $fundingVerified = false;
                    $paymentPolicyReady = false;
                    $fundingRequests[] = ['visitId' => (int) $visit->id, 'verified' => false, 'blocker' => $e->getMessage()];
                }
            }
            if (! $paymentPolicyReady) {
                $issues[] = 'VISIT_PAYMENT_POLICY_REVIEW_REQUIRED';
                if (collect($fundingRequests)->contains(fn ($r) => ($r['collectionMode'] ?? null) !== 'supervisor_manual')) {
                    $issues[] = 'CLEARED_FUNDING_SOURCE_INTEGRATION_REQUIRED';
                }
            }
            // The switch is an explicit operations rollout decision, not an assertion
            // that a worker-supplied header proves APK signing or device QA.
            if (! CleaningV3OperationalAdmissionService::enabled()) {
                $issues[] = 'SIGNED_APK_ROLLOUT_AND_VISIT_EXECUTION_QA_REQUIRED';
            }
            if (! config('cleaning_v3_catalog.cash_settlement_enabled', false) && CleaningV3OperationalAdmissionService::enabled()) {
                $issues[] = 'V3_CASH_SETTLEMENT_PAUSED';
            }
            return ['bookingId' => $bookingId, 'signedPriceVerified' => $valid,
                'visitCount' => $visits->count(), 'fundingVerified' => $fundingVerified, 'paymentPolicyReady' => $paymentPolicyReady, 'fundingRequests' => $fundingRequests,
                'canDispatch' => $issues === [], 'blockers' => array_values(array_unique($issues))];
        }, 3);
    }
}
