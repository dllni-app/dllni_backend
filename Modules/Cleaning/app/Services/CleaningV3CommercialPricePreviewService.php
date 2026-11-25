<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

use App\Models\CleaningFinancialSetting;
use App\Models\PlatformCoupon;
use App\Services\Coupons\PlatformCouponEligibilityService;
use DomainException;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

/**
 * Server-derived, non-binding commercial review. No quote, redemption, funding
 * reservation or ledger mutation. Uses persisted financial settings, never
 * fallback seed/default prices. Split visits share one appointment minimum.
 */
final class CleaningV3CommercialPricePreviewService
{
    public function __construct(private readonly CleaningV3VisitDiscountAllocator $discounts,
        private readonly PlatformCouponEligibilityService $eligibility) {}

    public function preview(CleaningV3CatalogVersion $catalog, array $plan, array $capacity,
        int $customerId, mixed $propertyKey, ?string $mode, mixed $couponCode, string $draftFingerprint, ?array $event = null, array $answers = [], array $fields = []): array
    {
        if (($event === null && ($capacity['checked'] ?? false) !== true) || ! extension_loaded('bcmath')) {
            throw new DomainException('V3_PRICING_CAPACITY_REQUIRED');
        }
        $settings = CleaningFinancialSetting::query()->orderBy('id')->limit(2)->get();
        if ($settings->count() !== 1) {
            throw new DomainException('V3_FINANCIAL_SETTINGS_UNAVAILABLE');
        }
        $setting = $settings->first();
        $transport = $this->money((string) $setting->travel_markup_value);
        $minimum = $this->money((string) $setting->cleaning_minimum_order_price);
        $supervisorRate = $this->money((string) $setting->supervisor_hourly_rate);
        $commissionType = (string) $setting->commission_type;
        $commission = $this->money((string) ($commissionType === 'fixed'
            ? $setting->commission_fixed_amount : $setting->default_commission_rate));
        if (! in_array($commissionType, ['fixed', 'percent'], true)
            || ($commissionType === 'percent' && bccomp($commission, '100', 2) > 0)
            || (string) $setting->travel_markup_type !== 'worker_allowance') {
            throw new DomainException('V3_FINANCIAL_SETTINGS_UNAVAILABLE');
        }

        $items = $catalog->items->keyBy('item_key');
        $eventRate = null;
        $eventTariff = null;
        $eventType = null;
        if ($event !== null) {
            $eventItem = $items->get($event['eventItemKey']);
            if ($eventItem === null || $eventItem->source_type !== 'event_type' || ! $eventItem->is_enabled) {
                throw new DomainException('V3_COMMERCIAL_EVENT_TARIFF_UNSUPPORTED');
            }
            // A configured event-specific tariff always takes precedence.
            // Disabled/gapped tariffs must never fall back to the global rate.
            if ($eventItem->tariffRules->isNotEmpty()) {
                $eventTariff = app(CleaningV3EventTariffPricing::class)->price($catalog, $eventItem, $plan, $event);
            } else {
                $eventRate = $this->money((string) $setting->extension_rate_per_30_minutes);
                if (bccomp($eventRate, '0', 2) <= 0) {
                    throw new DomainException('V3_FINANCIAL_SETTINGS_UNAVAILABLE');
                }
            }
            $eventType = \Modules\Cleaning\Models\CleaningEventType::query()->whereKey($eventItem->source_id)->value('slug');
            foreach ($fields as $field) {
                $key = $field['key'];
                if (! array_key_exists($key, $answers)) {
                    continue;
                }
                $item = $items->get($key);
                // Event hours are derived from the canonical schedule, not a
                // second numeric field. Do not omit other payable answers.
                if ($item === null || ($item->source_type !== 'equipment'
                    && ($item->tariffRules->where('is_enabled', true)->isNotEmpty()
                        || in_array($field['componentId'], ['numeric', 'stepper'], true)
                        || $item->source_type === 'event_type'))) {
                    throw new DomainException('V3_COMMERCIAL_EVENT_ANSWERS_UNSUPPORTED');
                }
            }
            $requests = app(CleaningV3EquipmentPreviewService::class)->requests($items, $answers);
            $capacity['equipmentPreview'] = app(CleaningV3EquipmentPreviewService::class)->scheduled($requests, $plan['appointments']);
        }
        $work = collect($capacity['visits'] ?? [])->keyBy('occurrenceKey');
        $equipment = collect($capacity['equipmentPreview']['visits'] ?? [])->keyBy('occurrenceKey');
        $rows = [];
        $totalEligible = '0.00';
        $totalBeforeDiscount = '0.00';
        foreach ($plan['appointments'] as $appointment) {
            $keys = [];
            $subtotal = '0.00';
            foreach ($appointment['visits'] as $visit) {
                $key = $visit['occurrenceKey'];
                $w = $work->get($key);
                $e = $equipment->get($key);
                if (isset($rows[$key]) || $e === null || ($event === null && ($w === null || $w['requestedWorkers'] !== $visit['requiredWorkers']))) {
                    throw new DomainException('V3_PRICING_VISIT_COVERAGE_MISMATCH');
                }
                $base = $eventTariff !== null
                    ? $this->money($eventTariff['visits'][$key] ?? null)
                    : ($eventRate === null ? '0.00' : $this->ceil(bcmul($eventRate, (string) (intdiv($visit['durationMinutes'], 30) * $visit['requiredWorkers']), 2)));
                $addons = '0.00';
                foreach ($w['taskGroups'] ?? [] as $group) {
                    $item = $items->get($group['itemKey']);
                    if ($item === null || $item->source_type === 'equipment' || $item->source_type === 'event_type') {
                        throw new DomainException('V3_COMMERCIAL_SERVICE_UNSUPPORTED');
                    }
                    $amount = $this->money($group['estimatedBaseAmountSyp']);
                    if (in_array($item->source_type, ['special_service', 'material'], true)) {
                        $addons = bcadd($addons, $amount, 2);
                    } else {
                        $base = bcadd($base, $amount, 2);
                    }
                }
                if ($event === null && bccomp(bcadd($base, $addons, 2), $w['estimatedBaseAmountSyp'], 2) !== 0) {
                    throw new DomainException('V3_TASK_PRICE_CONSERVATION_FAILED');
                }
                $eq = $this->money($e['estimatedEquipmentAmountSyp']);
                $workers = $visit['requiredWorkers'];
                $supervisor = $event === null && $workers > 1 ? $this->ceil(bcdiv(bcmul($supervisorRate, (string) $visit['durationMinutes'], 2), '60', 6)) : '0.00';
                $travel = $this->ceil(bcmul($transport, (string) $workers, 2));
                // Preserve exact published tariff amounts; legacy whole-lira
                // rounding applies to transport and scheduled supervisor fee.
                $eligible = bcadd(bcadd($base, $addons, 2), bcadd($eq, $supervisor, 2), 2);
                $gross = bcadd($eligible, $travel, 2);
                $rows[$key] = [
                    'appointmentKey' => $appointment['appointmentKey'], 'occurrenceKey' => $key,
                    'pricedWorkers' => $workers, 'baseAmountSyp' => $base, 'addonsAmountSyp' => $addons,
                    'equipmentAmountSyp' => $eq, 'supervisorAmountSyp' => $supervisor,
                    'minimumTopUpSyp' => '0.00', 'travelAmountSyp' => $travel,
                    'eligibleBeforeDiscountSyp' => $eligible,
                ];
                $keys[] = $key;
                $subtotal = bcadd($subtotal, $gross, 2);
            }
            if ($event === null && bccomp($subtotal, $minimum, 2) < 0) {
                $topUp = bcsub($minimum, $subtotal, 2);
                $first = $keys[0];
                $rows[$first]['minimumTopUpSyp'] = $topUp;
                $rows[$first]['eligibleBeforeDiscountSyp'] = bcadd($rows[$first]['eligibleBeforeDiscountSyp'], $topUp, 2);
            }
            foreach ($keys as $key) {
                $totalEligible = bcadd($totalEligible, $rows[$key]['eligibleBeforeDiscountSyp'], 2);
                $totalBeforeDiscount = bcadd($totalBeforeDiscount,
                    bcadd($rows[$key]['eligibleBeforeDiscountSyp'], $rows[$key]['travelAmountSyp'], 2), 2);
            }
        }
        if (($event === null && count($rows) !== $work->count()) || count($rows) !== $equipment->count()) {
            throw new DomainException('V3_PRICING_VISIT_COVERAGE_MISMATCH');
        }

        $coupon = null;
        $approvedDiscount = null;
        if ($couponCode !== null && $couponCode !== '') {
            if (! is_string($couponCode) || mb_strlen($couponCode) > 50 || trim($couponCode) === '') {
                throw new DomainException('V3_COUPON_INVALID');
            }
            $coupon = PlatformCoupon::query()->with('constraints')->where('code', mb_strtoupper(trim($couponCode)))->first();
            $property = $items->get($propertyKey ?? '');
            $propertyType = $event !== null ? 'event_assistance' : ($property?->source_type === 'property'
                ? CleaningHomeType::query()->whereKey($property->source_id)->value('booking_value') : null);
            if ($coupon === null || ! $this->eligibility->evaluate($coupon, $customerId, PlatformCoupon::SECTION_CLEANING,
                (float) $totalBeforeDiscount, ['propertyType' => $propertyType, 'cleaningMode' => $mode, 'eventType' => $eventType])['isValid']) {
                throw new DomainException('V3_COUPON_NOT_ELIGIBLE');
            }
            $value = $this->money((string) $coupon->discount_value);
            if ($coupon->discount_type === PlatformCoupon::DISCOUNT_PERCENTAGE) {
                if (bccomp($value, '100', 2) > 0) {
                    throw new DomainException('V3_COUPON_INVALID');
                }
                $amount = bcdiv(bcadd(bcdiv(bcmul($totalEligible, $value, 4), '100', 4), '0.0050', 4), '1', 2);
                if ($coupon->max_discount_amount !== null) {
                    $cap = $this->money((string) $coupon->max_discount_amount);
                    $amount = bccomp($amount, $cap, 2) > 0 ? $cap : $amount;
                }
            } elseif ($coupon->discount_type === PlatformCoupon::DISCOUNT_FIXED) {
                $amount = $value;
            } else {
                throw new DomainException('V3_COUPON_INVALID');
            }
            $approvedDiscount = ['type' => 'fixed', 'amount' => $amount];
        }
        $allocated = $this->discounts->allocate(array_map(static fn ($row) => [
            'visitKey' => $row['occurrenceKey'], 'pricedWorkers' => $row['pricedWorkers'],
            'eligibleNetBeforeDiscount' => $row['eligibleBeforeDiscountSyp'],
        ], array_values($rows)), $approvedDiscount);
        $totals = array_fill_keys(['baseAmountSyp', 'addonsAmountSyp', 'equipmentAmountSyp', 'supervisorAmountSyp',
            'minimumTopUpSyp', 'travelAmountSyp', 'discountAmountSyp', 'additionalWorkerFeeSyp', 'totalAmountSyp'], '0.00');
        foreach ($allocated['visits'] as $allocatedRow) {
            $key = $allocatedRow['visitKey'];
            $row = &$rows[$key];
            $row['discountAmountSyp'] = $allocatedRow['discountAllocated'];
            $row['additionalWorkerFeeSyp'] = $allocatedRow['additionalWorkerFee'];
            $row['totalAmountSyp'] = bcadd($allocatedRow['totalWithAdditionalWorkerFee'], $row['travelAmountSyp'], 2);
            foreach ($totals as $field => $amount) {
                $totals[$field] = $this->money(bcadd($amount, $row[$field], 2));
            }
            unset($row);
        }
        $settingsDigest = hash('sha256', json_encode([
            'id' => $setting->id, 'travel' => $transport, 'minimum' => $minimum,
            'supervisor' => $supervisorRate, 'commissionType' => $commissionType, 'commission' => $commission, 'eventRate' => $eventRate, 'eventTariff' => $eventTariff,
            'couponId' => $coupon?->id, 'couponValue' => $coupon?->discount_value,
            'couponType' => $coupon?->discount_type, 'couponCap' => $coupon?->max_discount_amount,
        ], JSON_THROW_ON_ERROR));
        $result = [
            'currency' => 'SYP', 'previewOnly' => true, 'canQuote' => false,
            'canCharge' => false, 'canCreateBooking' => false,
            'requiresCommercialApproval' => true, 'requiresFundingAdmission' => true,
            'couponCode' => $coupon?->code, 'totals' => $totals, 'visits' => array_values($rows),
        ];
        return [...$result, 'pricingFingerprint' => hash('sha256', json_encode([
            $customerId, $draftFingerprint, $settingsDigest, $result,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE))];
    }

    private function money(mixed $value): string
    {
        if (! is_string($value) || preg_match('/^(?:0|[1-9][0-9]{0,11})(?:\.[0-9]{1,2})?$/D', $value) !== 1) {
            throw new DomainException('V3_PRICING_MONEY_INVALID');
        }
        return bcadd($value, '0', 2);
    }

    private function ceil(string $value): string
    {
        $whole = bcadd($value, '0', 0);
        return bcadd($whole, bccomp($value, $whole, 6) > 0 ? '1' : '0', 2);
    }
}
