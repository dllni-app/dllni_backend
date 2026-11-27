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
use Modules\Cleaning\Services\CleaningV3DailyQuoteScope;
use Modules\Cleaning\Services\CleaningV3EquipmentPreviewService;
use Modules\Cleaning\Services\CleaningV3QuoteBookingAdmissionService;
use Modules\User\Models\UserAddress;

/** Materialize a confirmed unpaid booking, NOT permission to dispatch or work. */
final class CleaningV3CreateBookingController
{
    /**
     * Read-only recovery of an explicitly confirmed quote. The same locks as
     * creation serialize a lost-response read against an in-flight acceptance.
     * An unconsumed, unexpired quote is NOT proof that creation failed.
     * This route deliberately works when new commercial work is disabled.
     */
    public function confirmationOutcome(Request $request, string $quoteId): JsonResponse
    {
        $headers = ['Cache-Control' => 'private, no-store'];
        $customerId = (int) $request->user()->id;
        try {
            $outcome = DB::transaction(function () use ($customerId, $quoteId): array {
                DB::table('cleaning_v3_catalog_publication')->where('id', 1)->sharedLock()->first();
                DB::table('users')->where('id', $customerId)->lockForUpdate()->first();
                $quote = DB::table('cleaning_booking_quotes')->where('id', $quoteId)
                    ->where('customer_id', $customerId)->where('contract_version', 'v3')->lockForUpdate()->first();
                if ($quote === null) {
                    abort(404);
                }
                if ($quote->cleaning_booking_id === null) {
                    $expired = \Carbon\CarbonImmutable::parse((string) $quote->expires_at, 'UTC')
                        ->lte(\Carbon\CarbonImmutable::now('UTC'));
                    return ['quoteId' => $quoteId, 'outcome' => $expired ? 'rejected_expired' : 'unresolved'];
                }
                $envelope = json_decode((string) $quote->snapshot, true, flags: JSON_THROW_ON_ERROR);
                if (isset($envelope['snapshot']['dailyAppointments'])) {
                    $receipt = $this->dailyResult($quote, $envelope['snapshot'], true);
                } else {
                    $booking = CleaningBooking::query()->whereKey($quote->cleaning_booking_id)
                        ->where('customer_id', $customerId)->where('contract_version', 'v3')->firstOrFail();
                    $acceptedAt = data_get($booking->property_details, 'v3_quote_accepted_at');
                    $catalog = CleaningV3CatalogVersion::query()->whereKey($quote->catalog_version_id)->firstOrFail();
                    if (data_get($booking->property_details, 'v3_current_quote_id') !== $quote->id
                        || ! is_string($acceptedAt) || ($envelope['quoteId'] ?? null) !== $quote->id
                        || ! hash_equals((string) $quote->signature, (string) ($envelope['signature'] ?? ''))) {
                        throw new DomainException('V3_BOOKING_RECEIPT_MISMATCH');
                    }
                    $snapshot = app(\Modules\Cleaning\Services\CleaningV3SignedQuoteEnvelope::class)->verify(
                        $envelope, $customerId, $catalog->version_key, $catalog->published_digest,
                        \Carbon\CarbonImmutable::parse($acceptedAt)->utc(),
                    );
                    $receipt = $this->result($booking, true, $snapshot);
                }
                return ['quoteId' => $quoteId, 'outcome' => 'accepted', 'receipt' => $receipt];
            }, 3);
            return response()->json(['success' => true, 'data' => $outcome], 200, $headers);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'code' => $e->getMessage()], 409, $headers);
        }
    }

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
                    $envelope = json_decode((string) $quote->snapshot, true);
                    if (isset($envelope['snapshot']['dailyAppointments'])) {
                        $this->assertDailyReplay($quote, $envelope['snapshot'], $confirmation);
                        return $this->dailyResult($quote, $envelope['snapshot'], true);
                    }
                    $booking = CleaningBooking::query()->whereKey($quote->cleaning_booking_id)->where('customer_id', $customerId)->firstOrFail();
                    if (! hash_equals((string) data_get($booking->property_details, 'v3_acceptance_hash'), $acceptanceHash)) {
                        throw new DomainException('V3_BOOKING_QUOTE_ALREADY_CONSUMED');
                    }
                    return $this->result($booking, true, $envelope['snapshot'] ?? null);
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
                app(\Modules\Cleaning\Services\CleaningV3WorkRequirements::class)->assertCapacity($snapshot, $data['capacityCheck']);
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
                $daily = array_key_exists('dailyAppointments', $snapshot);
                $dailyEnabled = config('cleaning_v3_catalog.independent_daily_bookings_enabled', false);
                if ($daily && (! $dailyEnabled || $event !== null || $amendment !== null || ($data['recurrenceRule'] ?? null) === null)) {
                    throw new DomainException('V3_INDEPENDENT_DAILY_BOOKINGS_PAUSED');
                }
                if ($dailyEnabled && ($data['recurrenceRule'] ?? null) !== null && $event === null && $amendment === null && ! $daily) {
                    throw new DomainException('REQUOTE_REQUIRED');
                }
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
                $commercialTotals = $snapshot['commercialReview']['totals'];
                $address = $preferences['address'];
                $owned = UserAddress::query()->whereKey($address['addressId'])->firstOrFail();
                $groups = $daily ? $visits->map(fn ($visit) => collect([$visit])) : collect([$visits]);
                $bookings = [];
                $acceptedAt = now('UTC')->toIso8601String();
                $work = collect($data['capacityCheck']['visits'])->keyBy('occurrenceKey');
                foreach ($groups as $groupIndex => $visits) {
                $first = $visits->first();
                $totals = $daily ? $rows->get($first['occurrenceKey']) : $commercialTotals;
                // Respect the narrower historic booking money columns.
                if (CleaningV3CashSettlementBasis::cents($totals['totalAmountSyp']) > 9999999999) {
                    throw new DomainException('V3_BOOKING_AMOUNT_LIMIT');
                }
                $supervised = $event === null && $visits->max('requiredWorkers') > 1;
                $details = [
                    'address' => $address['address'], 'location_name' => $address['label'],
                    'address_details' => $address['details'], 'v3_address_id' => $address['addressId'],
                    'v3_excluded_worker_ids' => $preferences['excludedWorkerIds'],
                    'v3_admission_hold' => true, 'v3_acceptance_hash' => $acceptanceHash,
                    'v3_current_quote_id' => $quote->id, 'v3_quote_accepted_at' => $acceptedAt,
                    'v3_answers' => $answers, 'v3_scope_selections' => $draft['scopeSelections'] ?? [],
                    'cleaning_mode' => $draft['cleaningMode'] ?? null,
                    'schedule' => ['mode' => $data['recurrenceRule'] === null ? 'single' : 'recurring',
                        'rule' => $data['recurrenceRule'], 'skippedOccurrences' => $data['skippedOccurrences']],
                ];
                if ($daily || data_get($existingBooking?->property_details, 'v3_daily_origin_quote_id') !== null) {
                    $details['v3_daily_origin_quote_id'] = $daily ? $quote->id : data_get($existingBooking->property_details, 'v3_daily_origin_quote_id');
                    $details['v3_daily_origin_accepted_at'] = $daily ? $acceptedAt : data_get($existingBooking->property_details, 'v3_daily_origin_accepted_at');
                    $details['schedule'] = ['mode' => 'single'];
                }
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
                        bcadd(bcadd($totals['supervisorAmountSyp'], $totals['minimumTopUpSyp'], 2), $totals['commissionAmountSyp'] ?? '0.00', 2), 2),
                    'travel_fee' => $totals['travelAmountSyp'], 'total_price' => $totals['totalAmountSyp'],
                    'is_pricing_final' => true, 'terms_accepted' => true,
                ]);
                $booking->forceFill(['contract_version' => 'v3', 'catalog_version_id' => $catalog->id]);
                // Never fire legacy dispatch/repricing observers for an
                // unpaid, not yet operationally admitted booking.
                $booking->saveQuietly();
                if ($daily) {
                    DB::table('cleaning_quote_bookings')->insert([
                        'quote_id' => $quote->id, 'cleaning_booking_id' => $booking->id,
                        'appointment_sequence' => $groupIndex + 1,
                    ]);
                }
                if ($coupon !== null) {
                    // Existing redemption service serializes total and per-user
                    // use limits. Suppress legacy repricing/dispatch observers.
                    $subtotal = bcsub(bcadd($totals['totalAmountSyp'], $totals['discountAmountSyp'], 2),
                        $totals['additionalWorkerFeeSyp'], 2);
                    if ($groupIndex === 0) {
                        $commercialSubtotal = bcsub(bcadd($commercialTotals['totalAmountSyp'], $commercialTotals['discountAmountSyp'], 2),
                            $commercialTotals['additionalWorkerFeeSyp'], 2);
                        $redemption = CleaningBooking::withoutEvents(fn () => app(\App\Services\Coupons\PlatformCouponRedemptionService::class)->record(
                            $coupon, $customerId, PlatformCoupon::SECTION_CLEANING,
                            $commercialSubtotal, $commercialTotals['discountAmountSyp'], $booking,
                        ));
                        $redemption->forceFill(['funding_type' => PlatformCoupon::FUNDING_PLATFORM,
                            'platform_funded_amount' => $commercialTotals['discountAmountSyp'], 'merchant_funded_amount' => '0.00',
                            'funding_snapshot' => ['version' => 1, 'source' => 'v3_company_commission_cap', 'quoteId' => $quote->id],
                        ])->save();
                    }
                    $booking->forceFill(['platform_coupon_id' => $coupon->id, 'platform_coupon_code' => $coupon->code,
                        'discount_amount' => $totals['discountAmountSyp'],
                        'subtotal_before_discount' => $subtotal])->saveQuietly();
                }
                if ($existingBooking === null) {
                    DB::table('booking_status_logs')->insert([
                        'booking_id' => $booking->id, 'booking_type' => CleaningBooking::class,
                        'from_status' => null, 'to_status' => 'pending', 'created_at' => now(), 'updated_at' => now(),
                    ]);
                }
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
                            bcadd(bcadd($row['supervisorAmountSyp'], $row['minimumTopUpSyp'], 2), $row['commissionAmountSyp'] ?? '0.00', 2), 2),
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
                    if ($daily) {
                        app(CleaningV3DailyQuoteScope::class)->assertExecution($snapshot, $groupIndex + 1, $visit, true);
                    }
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
                $bookings[] = $booking;
                }
                $booking = $bookings[0];
                DB::table('cleaning_booking_quotes')->where('id', $quote->id)->update([
                    'cleaning_booking_id' => $booking->id, 'updated_at' => now(),
                    'v3_confirmed_draft' => json_encode($draft, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
                ]);
                if ($daily) {
                    $quote->cleaning_booking_id = $booking->id;
                    return $this->dailyResult($quote, $snapshot, false);
                }
                return $this->result($booking, false, $snapshot);
            }, 3);
            return response()->json(['success' => true, 'data' => $result], $result['replayed'] ? 200 : 201, $headers);
        } catch (DomainException $e) {
            return response()->json(['success' => false, 'code' => $e->getMessage(),
                'message' => 'تعذر تأكيد الحجز. راجع العرض والبيانات أو تواصل مع الإدارة.'], 409, $headers);
        }
    }

    private function assertDailyReplay(object $quote, array $snapshot, array $confirmation): void
    {
        $expected = [
            'quoteId' => $quote->id, 'idempotencyKey' => $quote->idempotency_key,
            'requestHash' => $quote->request_hash, 'quoteSignature' => $quote->signature,
            'acceptedTotalSyp' => $snapshot['totalWithAdditionalWorkerFees'],
            'pricingRevision' => $snapshot['pricingRevision'],
            'pricingFingerprint' => $snapshot['commercialReview']['pricingFingerprint'],
            'draftFingerprint' => $snapshot['commercialReview']['draftFingerprint'],
        ];
        ksort($expected);
        ksort($confirmation);
        if ($expected !== $confirmation) {
            throw new DomainException('V3_BOOKING_QUOTE_ALREADY_CONSUMED');
        }
    }

    private function dailyResult(object $quote, array $snapshot, bool $replayed): array
    {
        $links = DB::table('cleaning_quote_bookings')->where('quote_id', $quote->id)
            ->orderBy('appointment_sequence')->get();
        if ($links->count() !== count($snapshot['dailyAppointments'])
            || (int) $links->first()?->cleaning_booking_id !== (int) $quote->cleaning_booking_id) {
            throw new DomainException('V3_DAILY_BOOKING_RECEIPT_MISMATCH');
        }
        $catalog = CleaningV3CatalogVersion::query()->whereKey($quote->catalog_version_id)->firstOrFail();
        $envelope = json_decode((string) $quote->snapshot, true, flags: JSON_THROW_ON_ERROR);
        $scope = app(CleaningV3DailyQuoteScope::class);
        $receipt = [];
        $first = null;
        foreach ($links as $index => $link) {
            $booking = CleaningBooking::query()->whereKey($link->cleaning_booking_id)
                ->where('customer_id', $quote->customer_id)->where('contract_version', 'v3')
                ->where('catalog_version_id', $quote->catalog_version_id)->first();
            $accepted = data_get($booking?->property_details, 'v3_daily_origin_accepted_at');
            if ($booking === null || ! is_string($accepted)
                || data_get($booking->property_details, 'v3_daily_origin_quote_id') !== $quote->id
                || (int) $link->appointment_sequence !== $index + 1) {
                throw new DomainException('V3_DAILY_BOOKING_RECEIPT_MISMATCH');
            }
            if ($index === 0) {
                $first = $booking;
                $snapshot = app(\Modules\Cleaning\Services\CleaningV3SignedQuoteEnvelope::class)->verify(
                    $envelope, (int) $quote->customer_id, $catalog->version_key, $catalog->published_digest,
                    \Carbon\CarbonImmutable::parse($accepted)->utc(),
                );
                if (($envelope['quoteId'] ?? null) !== $quote->id
                    || ! hash_equals((string) $quote->signature, (string) ($envelope['signature'] ?? ''))) {
                    throw new DomainException('V3_DAILY_BOOKING_RECEIPT_MISMATCH');
                }
            }
            $plan = $scope->appointment($snapshot, $index + 1);
            $visits = $booking->sessions()->where('status', '!=', 'superseded')->get();
            $visit = $visits->first();
            $row = collect($snapshot['commercialReview']['visits'])->firstWhere('occurrenceKey', $plan['occurrenceKey']);
            if ($visits->count() !== 1 || $visit->occurrence_key !== $plan['occurrenceKey']
                || $visit->appointment_key !== $plan['appointmentKey'] || $row === null) {
                throw new DomainException('V3_DAILY_BOOKING_RECEIPT_MISMATCH');
            }
            $approved = app(\Modules\Cleaning\Services\CleaningV3OperationalAdmissionPolicy::class)->approved($booking);
            $receipt[] = [
                'bookingId' => (int) $booking->id, 'bookingNumber' => $booking->booking_number,
                'occurrenceKey' => $plan['occurrenceKey'], 'appointmentKey' => $plan['appointmentKey'],
                'scheduledDate' => $plan['scheduledDate'], 'scheduledTime' => $plan['scheduledTime'],
                'amount' => $row['totalAmountSyp'], 'visitId' => (int) $visit->id,
                // The receipt describes the original signed acceptance, not
                // a later revision of this independently editable booking.
                'priceRevision' => (int) $snapshot['pricingRevision'],
                'admissionStatus' => $booking->status->value === 'cancelled' ? 'cancelled'
                    : ($approved ? 'approved' : 'pending_operational_review'),
            ];
        }
        // The old singular aliases describe ONLY the first booking and its
        // own visit. Never send sibling IDs as actionable children of it.
        return [...$this->result($first, $replayed), 'receiptKind' => 'independent_daily',
            'confirmationQuoteId' => (string) $quote->id, 'bookings' => $receipt];
    }

    private function result(CleaningBooking $booking, bool $replayed, ?array $snapshot = null): array
    {
        $approved = app(\Modules\Cleaning\Services\CleaningV3OperationalAdmissionPolicy::class)->approved($booking);
        $result = ['bookingId' => (int) $booking->id, 'bookingNumber' => $booking->booking_number,
            'paymentStatus' => 'unpaid', 'admissionStatus' => $booking->status->value === 'cancelled' ? 'cancelled'
                : ($approved ? 'approved' : 'pending_operational_review'),
            'canDispatch' => $approved && $booking->status->value === 'pending', 'replayed' => $replayed,
            'visits' => $booking->sessions()->orderBy('sequence')->get()->map(fn ($v) => [
                'visitId' => (int) $v->id, 'occurrenceKey' => $v->occurrence_key,
                'appointmentKey' => $v->appointment_key, 'priceRevision' => (int) $v->price_revision,
            ])->all()];
        if ($snapshot !== null) {
            $firstVisit = $booking->sessions()->orderBy('sequence')->first();
            $result['bookings'] = [[
                'bookingId' => (int) $booking->id, 'bookingNumber' => $booking->booking_number,
                'occurrenceKey' => count($result['visits']) === 1 ? $firstVisit?->occurrence_key : null,
                'appointmentKey' => count($result['visits']) === 1 ? $firstVisit?->appointment_key : null,
                'scheduledDate' => $firstVisit?->scheduled_date?->format('Y-m-d'),
                'scheduledTime' => $firstVisit === null ? null : mb_substr((string) $firstVisit->scheduled_time, 0, 5),
                'amount' => $snapshot['totalWithAdditionalWorkerFees'],
                'admissionStatus' => $result['admissionStatus'],
                'visitId' => count($result['visits']) === 1 ? (int) $firstVisit->id : null,
            ]];
        }
        return $result;
    }
}
