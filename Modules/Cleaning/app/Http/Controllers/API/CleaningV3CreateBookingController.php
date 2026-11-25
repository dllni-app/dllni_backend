<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Controllers\API;

use App\Models\CleaningFinancialSetting;
use App\Models\PlatformCoupon;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;
use Modules\Cleaning\Services\CleaningV3CashSettlementBasis;
use Modules\Cleaning\Services\CleaningV3CustomerPreferences;
use Modules\Cleaning\Services\CleaningV3EquipmentPreviewService;
use Modules\Cleaning\Services\CleaningV3QuoteBookingAdmissionService;
use Modules\User\Models\UserAddress;

/** Materialize a confirmed unpaid booking, NOT permission to dispatch or work. */
final class CleaningV3CreateBookingController
{
    public function __invoke(Request $request): JsonResponse
    {
        if (! config('cleaning_v3_catalog.new_booking_admission_enabled', false)
            || ! config('cleaning_v3_catalog.admin_publication_enabled', false)
            || ! config('cleaning_v3_catalog.cash_quote_enabled', false)) {
            return app(CleaningV3FinancialHoldController::class)();
        }

        $headers = ['Cache-Control' => 'private, no-store'];
        $customerId = (int) $request->user()->id;
        $confirmation = $request->input('confirmation');
        $draft = $request->input('draft');
        if (array_diff(array_keys($request->all()), ['confirmation', 'draft', 'termsAccepted']) !== []
            || $request->input('termsAccepted') !== true || ! is_array($draft) || ! is_array($confirmation)
            || ! is_string($confirmation['quoteId'] ?? null) || ! Str::isUuid($confirmation['quoteId'])) {
            return response()->json(['success' => false, 'code' => 'INVALID_V3_BOOKING_REQUEST',
                'message' => 'يرجى تأكيد عرض السعر وشروط الحجز.'], 422, $headers);
        }
        try {
            $result = DB::transaction(function () use ($request, $customerId, $confirmation, $draft) {
                DB::table('cleaning_v3_catalog_publication')->where('id', 1)->sharedLock()->first();
                DB::table('users')->where('id', $customerId)->lockForUpdate()->first();
                $quote = DB::table('cleaning_booking_quotes')->where('id', $confirmation['quoteId'])
                    ->where('customer_id', $customerId)->where('contract_version', 'v3')->lockForUpdate()->first();
                if ($quote === null) {
                    throw new DomainException('V3_BOOKING_QUOTE_NOT_FOUND');
                }
                // A lost response is replayable only with exactly the original
                // confirmation, even after expiry or a catalog activation.
                $canonicalConfirmation = $confirmation;
                ksort($canonicalConfirmation);
                $acceptanceHash = hash('sha256', json_encode($canonicalConfirmation, JSON_THROW_ON_ERROR));
                if ($quote->cleaning_booking_id !== null) {
                    $booking = CleaningBooking::query()->whereKey($quote->cleaning_booking_id)->where('customer_id', $customerId)->firstOrFail();
                    if (! hash_equals((string) data_get($booking->property_details, 'v3_acceptance_hash'), $acceptanceHash)) {
                        throw new DomainException('V3_BOOKING_QUOTE_ALREADY_CONSUMED');
                    }
                    return $this->result($booking, true);
                }
                $preferences = app(CleaningV3CustomerPreferences::class)->resolve($customerId, $draft['preferences'] ?? null, true);
                // Hold the persisted pricing inputs until the booking is linked.
                CleaningFinancialSetting::query()->orderBy('id')->lockForUpdate()->get();
                if (isset($draft['couponCode']) && is_string($draft['couponCode'])) {
                    PlatformCoupon::query()->where('code', mb_strtoupper(trim($draft['couponCode'])))->lockForUpdate()->first();
                }
                $draft['pricingPreviewRequested'] = true;
                $draft['expectedPricingFingerprint'] = $confirmation['pricingFingerprint'] ?? null;
                $previewRequest = $request->duplicate([], $draft);
                $previewRequest->setJson(new \Symfony\Component\HttpFoundation\InputBag($draft));
                $previewRequest->setUserResolver($request->getUserResolver());
                $response = app()->call(app(CleaningV3VisitPlanPreviewController::class), ['request' => $previewRequest]);
                if ($response->getStatusCode() !== 200) {
                    throw new DomainException($response->getData(true)['code'] ?? 'REQUOTE_REQUIRED');
                }
                $data = $response->getData(true)['data'];
                $review = $data['commercialPricePreview'];
                $snapshot = app(CleaningV3QuoteBookingAdmissionService::class)->assertCustomerConfirmation(
                    $customerId, $confirmation, $review['pricingFingerprint'], $data['draftFingerprint'],
                );
                if (($snapshot['paymentMethod'] ?? null) !== 'cash_on_delivery'
                    || ($snapshot['paymentStatus'] ?? null) !== 'unpaid'
                    || ! isset($snapshot['settlementBasis'])) {
                    throw new DomainException('REQUOTE_REQUIRED');
                }
                $amendment = $snapshot['amendment'] ?? null;
                $existingBooking = null;
                $existingVisits = null;
                if ($amendment !== null) {
                    [$existingBooking, $existingVisits, $previousQuote] = app(\Modules\Cleaning\Services\CleaningV3PendingRevisionService::class)
                        ->lock($customerId, $amendment['bookingId'], $amendment['previousRevision']);
                    if ($previousQuote->id !== $amendment['previousQuoteId']
                        || (int) $existingBooking->catalog_version_id !== (int) $quote->catalog_version_id) {
                        throw new DomainException('V3_PRICE_REVISION_FROZEN_OR_STALE');
                    }
                    app(\Modules\Cleaning\Services\CleaningV3PendingRevisionService::class)->assertSameVisits($existingVisits, $data, $review);
                    $existingVisits = $existingVisits->keyBy('occurrence_key');
                } elseif ($snapshot['pricingRevision'] !== 1) {
                    throw new DomainException('INVALID_V3_PRICE_REVISION');
                }
                // Reserve the coupon's usage under the same lock as the
                // confirmed price. This is NOT cleared financial funding.
                $coupon = $review['couponCode'] === null ? null : PlatformCoupon::query()
                    ->where('code', $review['couponCode'])->lockForUpdate()->firstOrFail();
                if ($coupon !== null && ($coupon->funding_type !== PlatformCoupon::FUNDING_PLATFORM
                    || ($coupon->max_platform_funding_amount !== null
                        && bccomp($review['totals']['discountAmountSyp'], (string) $coupon->max_platform_funding_amount, 2) > 0))) {
                    throw new DomainException('V3_COUPON_FUNDING_POLICY_REVIEW_REQUIRED');
                }
                $catalog = CleaningV3CatalogVersion::query()->whereKey($quote->catalog_version_id)->firstOrFail();
                $catalog->load('items.tariffRules');
                $fields = app(\Modules\Cleaning\Services\CleaningV3PropertyFormSelector::class)->select(
                    app(\Modules\Cleaning\Services\CleaningV3CatalogPublicSerializer::class)->serialize($catalog, 'ar')['items'], $draft['propertyKey'] ?? null,
                );
                $fields = array_values(array_filter($fields, static fn ($f) => $f['sourceType'] !== 'event_type'));
                $answers = app(\Modules\Cleaning\Services\CleaningV3PublishedFormValidator::class)->normalize($fields, $draft['answers']);
                $equipment = app(CleaningV3EquipmentPreviewService::class);
                $requests = $equipment->requests($catalog->items, $answers);
                $allocation = $equipment->allocate($requests, $data['appointments'], true);
                $event = $data['event'] ?? null;
                $propertyItem = $catalog->items->firstWhere('item_key', $draft['propertyKey'] ?? '');
                if ($propertyItem === null && $catalog->items->where('source_type', 'property')->where('is_enabled', true)->count() === 1) {
                    $propertyItem = $catalog->items->where('source_type', 'property')->where('is_enabled', true)->first();
                }
                $property = $event !== null ? 'event_assistance' : ($propertyItem?->source_type === 'property'
                    ? \Modules\Cleaning\Models\CleaningHomeType::query()->whereKey($propertyItem->source_id)->value('booking_value') : null);
                if (! is_string($property) || trim($property) === '') {
                    throw new DomainException('V3_PROPERTY_REQUIRED');
                }
                $visits = collect($data['appointments'])->flatMap(fn ($a) => array_map(fn ($v) => [...$v, 'appointmentKey' => $a['appointmentKey']], $a['visits']))
                    ->sortBy('startAt')->values();
                $rows = collect($snapshot['commercialReview']['visits'])->keyBy('occurrenceKey');
                $totals = $snapshot['commercialReview']['totals'];
                // Respect the narrower historic booking money columns.
                if (CleaningV3CashSettlementBasis::cents($totals['totalAmountSyp']) > 9999999999) {
                    throw new DomainException('V3_BOOKING_AMOUNT_LIMIT');
                }
                $address = $preferences['address'];
                $owned = UserAddress::query()->whereKey($address['addressId'])->firstOrFail();
                $first = $visits->first();
                $supervised = $event === null && $visits->max('requiredWorkers') > 1;
                $details = [
                    'address' => $address['address'], 'location_name' => $address['label'],
                    'address_details' => $address['details'], 'v3_address_id' => $address['addressId'],
                    'v3_excluded_worker_ids' => $preferences['excludedWorkerIds'],
                    'v3_admission_hold' => true, 'v3_acceptance_hash' => $acceptanceHash,
                    'v3_current_quote_id' => $quote->id, 'v3_quote_accepted_at' => now('UTC')->toIso8601String(),
                    'v3_answers' => $answers, 'v3_scope_selections' => $draft['scopeSelections'] ?? [],
                    'cleaning_mode' => $draft['cleaningMode'] ?? null,
                    'schedule' => ['mode' => $data['recurrenceRule'] === null ? 'single' : 'recurring',
                        'rule' => $data['recurrenceRule'], 'skippedOccurrences' => $data['skippedOccurrences']],
                ];
                if ($existingBooking !== null && (string) $existingBooking->property_type !== $property) {
                    throw new DomainException('V3_REVISION_PROPERTY_CHANGED');
                }
                $booking = $existingBooking ?? new CleaningBooking;
                $booking->fill([
                    'customer_id' => $customerId, 'booking_number' => $existingBooking?->booking_number ?? 'CLN-V3-'.Str::upper(Str::random(20)),
                    'status' => 'pending', 'booking_kind' => $supervised ? 'supervised_team' : 'standard',
                    'property_type' => $property, 'property_details' => $details,
                    'cleaning_event_type_id' => $event === null ? null : $catalog->items->firstWhere('item_key', $event['eventItemKey'])?->source_id,
                    'assignment_mode' => 'open_count', 'worker_scope' => 'any',
                    'number_of_workers' => $visits->max('requiredWorkers'), 'gender_preference' => $preferences['genderPreference'],
                    'address_latitude' => $address['latitude'], 'address_longitude' => $address['longitude'],
                    'neighborhood_name' => $owned->neighborhood,
                    'scheduled_date' => $first['scheduledDate'], 'scheduled_time' => $first['scheduledTime'],
                    'total_hours' => $visits->sum('durationMinutes') / 60,
                    'estimated_hours' => $visits->sum('durationMinutes') / 60,
                    'base_price' => $totals['baseAmountSyp'],
                    'addons_total' => bcadd(bcadd($totals['addonsAmountSyp'], $totals['equipmentAmountSyp'], 2),
                        bcadd($totals['supervisorAmountSyp'], $totals['minimumTopUpSyp'], 2), 2),
                    'travel_fee' => $totals['travelAmountSyp'], 'total_price' => $totals['totalAmountSyp'],
                    'is_pricing_final' => true, 'terms_accepted' => true,
                ]);
                $booking->forceFill(['contract_version' => 'v3', 'catalog_version_id' => $catalog->id]);
                // Never fire legacy dispatch/repricing observers for an
                // unpaid, not yet operationally admitted booking.
                $booking->saveQuietly();
                if ($coupon !== null) {
                    // Existing redemption service serializes total and per-user
                    // use limits. Suppress legacy repricing/dispatch observers.
                    $subtotal = bcsub(bcadd($totals['totalAmountSyp'], $totals['discountAmountSyp'], 2),
                        $totals['additionalWorkerFeeSyp'], 2);
                    $redemption = CleaningBooking::withoutEvents(fn () => app(\App\Services\Coupons\PlatformCouponRedemptionService::class)->record(
                        $coupon, $customerId, PlatformCoupon::SECTION_CLEANING,
                        (float) $subtotal, (float) $totals['discountAmountSyp'], $booking,
                    ));
                    $redemption->forceFill(['funding_type' => PlatformCoupon::FUNDING_PLATFORM,
                        'platform_funded_amount' => $totals['discountAmountSyp'], 'merchant_funded_amount' => '0.00',
                        'funding_snapshot' => ['version' => 1, 'source' => 'v3_company_commission_cap', 'quoteId' => $quote->id],
                    ])->save();
                    $booking->forceFill(['discount_amount' => $totals['discountAmountSyp'],
                        'subtotal_before_discount' => $subtotal])->saveQuietly();
                }
                if ($existingBooking === null) {
                    DB::table('booking_status_logs')->insert([
                        'booking_id' => $booking->id, 'booking_type' => CleaningBooking::class,
                        'from_status' => null, 'to_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
                $work = collect($data['capacityCheck']['visits'])->keyBy('occurrenceKey');
                foreach ($visits as $index => $plan) {
                    $key = $plan['occurrenceKey'];
                    $row = $rows->get($key);
                    $basis = $snapshot['settlementBasis']['visits'][$key];
                    $visit = $existingVisits === null ? $booking->sessions()->make() : $existingVisits->get($key);
                    $visit->fill([
                        'sequence' => $index + 1, 'session_type' => $event === null ? 'recurring_cleaning' : 'event_day',
                        'calculation_mode' => $event === null ? 'task' : 'time',
                        'scheduled_date' => $plan['scheduledDate'], 'scheduled_time' => $plan['scheduledTime'],
                        'duration_hours' => $plan['durationMinutes'] / 60, 'required_workers' => $plan['requiredWorkers'],
                        'supervision_mode' => $event === null && $plan['requiredWorkers'] > 1 ? 'supervisor' : 'worker',
                        'status' => 'scheduled', 'coverage_status' => 'searching', 'payment_status' => 'pending',
                        'is_pricing_final' => true, 'base_price' => $row['baseAmountSyp'],
                        'addons_total' => bcadd(bcadd($row['addonsAmountSyp'], $row['equipmentAmountSyp'], 2),
                            bcadd($row['supervisorAmountSyp'], $row['minimumTopUpSyp'], 2), 2),
                        'materials_total' => $basis['materialAmountSyp'],
                        'special_services_total' => bcsub($row['addonsAmountSyp'], $basis['materialAmountSyp'], 2),
                        'travel_fee' => $row['travelAmountSyp'], 'total_price' => $row['totalAmountSyp'],
                        'pricing_snapshot' => ['contractVersion' => 'v3', 'quoteId' => $quote->id,
                            'commercialRow' => $row, 'settlementBasis' => $basis,
                            'taskGroups' => $work->get($key)['taskGroups'] ?? [], 'event' => $event,
                            'equipmentRequests' => array_map(fn ($r) => array_intersect_key($r, array_flip(['itemKey','quantity','label','unitPriceSyp'])), $requests)],
                    ]);
                    $visit->forceFill(['appointment_key' => $plan['appointmentKey'], 'occurrence_key' => $key,
                        'price_revision' => $snapshot['pricingRevision']])->saveQuietly();
                    // Persist equipment allocations against the real visit.
                    foreach ($allocation[$key] ?? [] as $entries) {
                        foreach ($entries as $entry) {
                            \Modules\Cleaning\Models\CleaningEquipmentReservation::query()->create([
                                'cleaning_special_service_equipment_id' => $entry['assetId'],
                                'cleaning_booking_id' => $booking->id, 'cleaning_booking_session_id' => $visit->id,
                                'reserved_from' => \Carbon\CarbonImmutable::createFromTimestampUTC($entry['from']),
                                'reserved_until' => \Carbon\CarbonImmutable::createFromTimestampUTC($entry['until']),
                                'status' => 'reserved',
                            ]);
                        }
                    }
                }
                DB::table('cleaning_booking_quotes')->where('id', $quote->id)->update([
                    'cleaning_booking_id' => $booking->id, 'updated_at' => now(),
                    'v3_confirmed_draft' => json_encode($draft, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);
                return $this->result($booking, false);
            }, 3);
            return response()->json(['success' => true, 'data' => $result], $result['replayed'] ? 200 : 201, $headers);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'code' => $e->getMessage(),
                'message' => 'تعذر تأكيد الحجز. راجع العرض والبيانات أو تواصل مع الإدارة.'], 409, $headers);
        }
    }

    private function result(CleaningBooking $booking, bool $replayed): array
    {
        return ['bookingId' => (int) $booking->id, 'bookingNumber' => $booking->booking_number,
            'paymentStatus' => 'unpaid', 'admissionStatus' => $booking->status->value === 'cancelled' ? 'cancelled' : 'pending_operational_review',
            'canDispatch' => false, 'replayed' => $replayed,
            'visits' => $booking->sessions()->orderBy('sequence')->get()->map(fn ($v) => [
                'visitId' => (int) $v->id, 'occurrenceKey' => $v->occurrence_key,
                'appointmentKey' => $v->appointment_key, 'priceRevision' => (int) $v->price_revision,
            ])->all()];
    }
}
