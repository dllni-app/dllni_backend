<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3AdminCatalogPublication;
use Modules\Cleaning\Services\CleaningV3CommercialQuoteSnapshot;
use Modules\Cleaning\Services\CleaningV3QuoteArchiveService;
use Modules\Cleaning\Services\CleaningV3SignedQuoteEnvelope;

/** A price commitment for cash on service delivery, not a payment or booking. */
final class CleaningV3CashQuoteController
{
    public function __invoke(Request $request): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        if (! config('cleaning_v3_catalog.cash_quote_enabled', false)
            || ! config('cleaning_v3_catalog.admin_publication_enabled', false)
            || ! config('cleaning_v3_catalog.new_quote_archive_enabled', false)
            || ! config('cleaning_v3_catalog.read_enabled', false)) {
            return app(CleaningV3FinancialHoldController::class)();
        }
        $key = $request->input('idempotencyKey');
        $expected = $request->input('expectedPricingFingerprint');
        if (! is_string($key) || ! Str::isUuid($key) || ! is_string($expected)
            || preg_match('/^[a-f0-9]{64}$/D', $expected) !== 1
            || ! is_string($request->input('catalogVersionKey'))
            || ! is_string($request->input('catalogRevision'))
            || $request->input('pricingPreviewRequested') !== true) {
            return response()->json(['success' => false, 'code' => 'INVALID_V3_CASH_QUOTE_REQUEST',
                'message' => 'راجع السعر التقديري قبل إصدار العرض.'], 422, $headers);
        }
        $customerId = (int) $request->user()->id;
        try {
            return DB::transaction(function () use ($request, $key, $expected, $customerId, $headers) {
                // Serialize issuance with catalog activation and other requests
                // by this customer. Price changes still require a locked recheck
                // in the future booking writer.
                DB::table('cleaning_v3_catalog_publication')->where('id', 1)->sharedLock()->first();
                DB::table('users')->where('id', $customerId)->lockForUpdate()->first();
                $digest = CleaningV3AdminCatalogPublication::digest();
                if ($digest === null || $request->input('catalogRevision') !== $digest) {
                    throw new DomainException('REQUOTE_REQUIRED');
                }
                $catalog = CleaningV3CatalogVersion::query()->where('version_key', $request->input('catalogVersionKey'))
                    ->where('published_digest', $digest)->where('status', 'published')->first();
                if ($catalog === null) {
                    throw new DomainException('REQUOTE_REQUIRED');
                }
                // Re-run the exact public validation/pricing pipeline. The
                // request contains no server totals, payment status or funding.
                $amends = $request->input('revisionBookingId');
                $revision = $request->input('expectedPriceRevision');
                $amendment = null;
                $oldVisits = null;
                if ($amends !== null || $revision !== null) {
                    if (! config('cleaning_v3_catalog.new_booking_admission_enabled', false)
                        || ! is_int($amends) || $amends < 1 || ! is_int($revision)) {
                        throw new DomainException('INVALID_V3_PRICE_REVISION');
                    }
                    [$oldBooking, $oldVisits, $oldQuote] = app(\Modules\Cleaning\Services\CleaningV3PendingRevisionService::class)
                        ->lock($customerId, $amends, $revision);
                    if ((int) $oldBooking->catalog_version_id !== (int) $catalog->id) {
                        throw new DomainException('V3_REVISION_CATALOG_CHANGED');
                    }
                    $amendment = ['bookingId' => $amends, 'previousQuoteId' => $oldQuote->id, 'previousRevision' => $revision];
                }
                $payload = $request->except(['idempotencyKey', 'revisionBookingId', 'expectedPriceRevision']);
                $previewRequest = $request->duplicate($payload, $payload);
                if ($request->isJson()) {
                    $previewRequest->setJson(new \Symfony\Component\HttpFoundation\InputBag($payload));
                }
                $previewRequest->setUserResolver($request->getUserResolver());
                $response = app()->call(app(CleaningV3VisitPlanPreviewController::class), ['request' => $previewRequest]);
                if ($response->getStatusCode() !== 200) {
                    return $response;
                }
                $data = $response->getData(true)['data'];
                $review = $data['commercialPricePreview'] ?? null;
                if (! is_array($review) || ! hash_equals($expected, $review['pricingFingerprint'])) {
                    throw new DomainException('REQUOTE_REQUIRED');
                }
                if ($review['couponCode'] !== null && config('cleaning_v3_catalog.new_booking_admission_enabled', false)) {
                    $coupon = \App\Models\PlatformCoupon::query()->where('code', $review['couponCode'])->lockForUpdate()->firstOrFail();
                    if ($coupon->funding_type !== \App\Models\PlatformCoupon::FUNDING_PLATFORM
                        || ($coupon->max_platform_funding_amount !== null && bccomp($review['totals']['discountAmountSyp'], (string) $coupon->max_platform_funding_amount, 2) > 0)) {
                        throw new DomainException('V3_COUPON_FUNDING_POLICY_REVIEW_REQUIRED');
                    }
                }
                $plan = array_intersect_key($data, array_flip(['contractVersion', 'catalogVersionKey', 'catalogRevision', 'timezone', 'appointments']));
                $plan['draftFingerprint'] = hash('sha256', json_encode($plan, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
                if ($amendment !== null) {
                    app(\Modules\Cleaning\Services\CleaningV3PendingRevisionService::class)->assertSameVisits($oldVisits, $plan, $review);
                }
                $snapshot = app(CleaningV3CommercialQuoteSnapshot::class)->assemble($plan, $review, $data['draftFingerprint'], $amendment === null ? 1 : $revision + 1);
                if ($amendment !== null) {
                    $snapshot['amendment'] = $amendment;
                }
                // Signed payment policy explicitly prohibits treating a quote
                // as collected money. No funding, coupon redemption or stock write.
                $snapshot['paymentMethod'] = 'cash_on_delivery';
                $snapshot['paymentStatus'] = 'unpaid';
                $snapshot['settlementBasis'] = $review['settlementBasis'];
                if (($data['event'] ?? null) === null) {
                    $snapshot['workRequirementsDigests'] = app(\Modules\Cleaning\Services\CleaningV3WorkRequirements::class)
                        ->digests($data['capacityCheck']);
                }
                if (config('cleaning_v3_catalog.independent_daily_bookings_enabled', false)
                    && ($data['recurrenceRule'] ?? null) !== null && $amendment === null) {
                    $snapshot['dailyAppointments'] = app(\Modules\Cleaning\Services\CleaningV3DailyQuoteScope::class)->fromPlan($plan);
                }
                $identity = [$customerId, $digest, $data['draftFingerprint'], $review['pricingFingerprint'], 'cash_on_delivery'];
                if ($amendment !== null) {
                    $identity[] = $amendment;
                }
                $hash = hash('sha256', json_encode($identity, JSON_THROW_ON_ERROR));
                $archive = app(CleaningV3QuoteArchiveService::class);
                $signed = $archive->findReplay($customerId, $catalog->id, $key, $hash);
                if ($signed === null) {
                    $signed = app(CleaningV3SignedQuoteEnvelope::class)->seal($customerId, $snapshot);
                    $archive->archive($customerId, $catalog->id, $key, $hash, $signed);
                }
                return response()->json(['success' => true, 'data' => [
                    ...$signed, 'idempotencyKey' => $key, 'requestHash' => $hash,
                    'paymentMethod' => 'cash_on_delivery', 'paymentStatus' => 'unpaid',
                    'canCreateBooking' => false, 'canCharge' => false, 'canSettle' => false,
                    'canSubmitPendingBooking' => config('cleaning_v3_catalog.new_booking_admission_enabled', false),
                ]], 200, $headers);
            }, 3);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'code' => $e->getMessage(),
                'message' => 'تعذر إصدار العرض. أعد مراجعة السعر والبيانات.'], 409, $headers);
        }
    }
}
