<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

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
            $isQa = $catalog?->version_key === CleaningV3QaEnvironment::CATALOG_KEY;
            $valid = false;
            if ($catalog !== null) {
                try {
                    [$quote, $snapshot, $sequence] = app(CleaningV3AcceptedQuoteResolver::class)
                        ->load($booking, 'INVALID_QUOTE');
                    $rows = collect($snapshot['commercialReview']['visits'] ?? [])->keyBy('occurrenceKey');
                    if ($sequence !== null) {
                        // Only the selected signed occurrence is reviewed; the
                        // original commercial quote remains unchanged.
                        if ($visits->count() !== 1) {
                            throw new DomainException('SIGNED_VISIT_SCHEDULE_MISMATCH');
                        }
                        app(CleaningV3DailyQuoteScope::class)->assertExecution($snapshot, $sequence, $visits->first(), true);
                        $rows = $rows->only([$visits->first()->occurrence_key]);
                    } else {
                    // The signed draft fingerprint binds the canonical visit
                    // dates, times, durations and crews. Reconstruct it from
                    // persisted sessions, not the mutable request archive.
                    $appointments = $visits->groupBy('appointment_key')->map(
                        fn ($group, $key) => ['appointmentKey' => (string) $key,
                            'visits' => $group->map(fn ($visit) => [
                                'occurrenceKey' => $visit->occurrence_key,
                                'scheduledDate' => $visit->scheduled_date?->format('Y-m-d'),
                                'scheduledTime' => mb_substr((string) $visit->scheduled_time, 0, 5),
                                'durationMinutes' => (int) round((float) $visit->duration_hours * 60),
                                'requiredWorkers' => $visit->requiredWorkerCount(),
                            ])->values()->all()]
                    )->values()->all();
                    $plan = app(CleaningV3VisitPlanner::class)->plan([
                        'contractVersion' => 3,
                        'catalogVersionKey' => $catalog->version_key,
                        'catalogRevision' => $catalog->published_digest,
                        'appointments' => $appointments,
                    ], allowMultipleLocalDates: true);
                    if (! hash_equals($snapshot['draftFingerprint'], $plan['draftFingerprint'])) {
                        throw new DomainException('SIGNED_VISIT_SCHEDULE_MISMATCH');
                    }
                    }
                    $expectedTotal = $sequence === null ? $snapshot['totalWithAdditionalWorkerFees']
                        : $rows->first()['totalAmountSyp'];
                    $valid = ($snapshot['paymentMethod'] ?? null) === 'cash_on_delivery'
                        && ($snapshot['paymentStatus'] ?? null) === 'unpaid' && $rows->count() === $visits->count()
                        && $visits->isNotEmpty() && bccomp($expectedTotal, (string) $booking->getRawOriginal('total_price'), 2) === 0;
                    $draft = json_decode((string) $quote->v3_confirmed_draft, true);
                    $prefs = app(CleaningV3CustomerPreferences::class)->resolve((int) $booking->customer_id, $draft['preferences'] ?? null, true);
                    $valid = $valid && (int) data_get($booking->property_details, 'v3_address_id') === (int) $prefs['address']['addressId']
                        && abs((float) $booking->address_latitude - (float) $prefs['address']['latitude']) < 0.000001
                        && abs((float) $booking->address_longitude - (float) $prefs['address']['longitude']) < 0.000001
                        && ($booking->gender_preference?->value ?? 'any') === $prefs['genderPreference']
                        && data_get($booking->property_details, 'v3_excluded_worker_ids') === $prefs['excludedWorkerIds'];
                    $confirmed = collect($draft['appointments'] ?? [])->flatMap(fn ($a) => collect($a['visits'] ?? [])
                        ->map(fn ($v) => [...$v, 'appointmentKey' => $a['appointmentKey']]))->keyBy('occurrenceKey');
                    if ($sequence !== null) {
                        $confirmed = $confirmed->only([$visits->first()->occurrence_key]);
                    }
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
                || $v->startsAt() === null || ! $v->startsAt()->isFuture() || $v->workerAssignments()->exists()
                || $v->payment_status !== 'pending' || DB::table('cleaning_v3_visit_dispatches')
                    ->where('cleaning_booking_session_id', $v->id)->exists())) {
                $issues[] = 'VISIT_SCHEDULE_OR_ROSTER_REVIEW_REQUIRED';
            }
            if (! $isQa) {
                try {
                    app(CleaningWorkerApkUpdateManifestService::class)->manifest();
                } catch (DomainException) {
                    $issues[] = 'WORKER_APK_MANIFEST_UNAVAILABLE';
                }
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
            if (! config('cleaning_v3_catalog.operational_approval_enabled', false)) {
                $issues[] = 'V3_OPERATIONAL_APPROVAL_DISABLED';
            }
            $release = app(CleaningV3ReleaseReadiness::class)->audit();
            if ($isQa) {
                $digest = CleaningV3AdminCatalogPublication::qaDigest();
                if ($digest === null || ! hash_equals($digest, (string) $catalog->published_digest)
                    || ! config('cleaning_v3_catalog.admin_publication_enabled', false)
                    || ! config('cleaning_v3_catalog.read_enabled', false)) {
                    $issues[] = 'V3_QA_PUBLICATION_NOT_READY';
                }
                if (collect($fundingRequests)->contains(fn ($request) => ($request['collectionMode'] ?? null) !== 'supervisor_manual')) {
                    $issues[] = 'V3_QA_MANUAL_CASH_POLICY_REQUIRED';
                }
            } else {
                if (($release['readyForCommercialLaunch'] ?? false) !== true || ($release['blockerCodes'] ?? null) !== []) {
                    $issues[] = 'V3_COMMERCIAL_RELEASE_NOT_READY';
                }
                if (in_array('mysql_concurrency_and_mobile_e2e_evidence', $release['blockerCodes'] ?? [], true)) {
                    $issues[] = 'SIGNED_APK_ROLLOUT_AND_VISIT_EXECUTION_QA_REQUIRED';
                }
            }
            if (! $isQa && ! config('cleaning_v3_catalog.cash_settlement_enabled', false)) {
                $issues[] = 'V3_CASH_SETTLEMENT_PAUSED';
            }
            return ['bookingId' => $bookingId, 'signedPriceVerified' => $valid,
                'visitCount' => $visits->count(), 'fundingVerified' => $fundingVerified, 'paymentPolicyReady' => $paymentPolicyReady, 'fundingRequests' => $fundingRequests,
                'canDispatch' => $issues === [], 'blockers' => array_values(array_unique($issues)),
                'admissionScope' => $isQa ? 'qa' : 'commercial',
                'releaseBlockers' => $release['blockerCodes'] ?? []];
        }, 3);
    }
}
