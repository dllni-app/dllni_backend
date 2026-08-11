<?php

declare(strict_types=1);

namespace App\Services\Coupons;

use App\Models\PlatformCoupon;
use App\Models\PlatformCouponConstraint;

final class PlatformCouponEligibilityService
{
    /** @return array{isValid: bool, reason: string} */
    public function evaluate(
        PlatformCoupon $coupon,
        int $userId,
        string $section,
        float $subtotal,
        array $context = [],
    ): array {
        if (! $coupon->is_active) {
            return $this->invalid('inactive');
        }

        if ($coupon->starts_at && now()->lt($coupon->starts_at)) {
            return $this->invalid('not_started');
        }

        if ($coupon->expires_at && now()->gt($coupon->expires_at)) {
            return $this->invalid('expired');
        }

        if (! in_array($coupon->section, [$section, PlatformCoupon::SECTION_ALL], true)) {
            return $this->invalid('wrong_section');
        }

        if (
            $coupon->audience_type === PlatformCoupon::AUDIENCE_SPECIFIC_USERS
            && ! $coupon->users()->whereKey($userId)->exists()
        ) {
            return $this->invalid('not_assigned_to_user');
        }

        if ($coupon->total_usage_limit !== null && $coupon->used_count >= $coupon->total_usage_limit) {
            return $this->invalid('global_usage_limit_reached');
        }

        if ($coupon->per_user_usage_limit !== null) {
            $redemptions = $coupon->redemptions()->where('user_id', $userId)->count();
            if ($redemptions >= $coupon->per_user_usage_limit) {
                return $this->invalid('user_usage_limit_reached');
            }
        }

        if ($coupon->min_order_amount !== null && $subtotal < (float) $coupon->min_order_amount) {
            return $this->invalid('min_order_not_met');
        }

        if ($section === PlatformCoupon::SECTION_CLEANING) {
            return $this->evaluateCleaningConstraints($coupon, $context);
        }

        return ['isValid' => true, 'reason' => 'ok'];
    }

    public function calculateDiscount(PlatformCoupon $coupon, float $subtotal): float
    {
        if ($coupon->discount_type === PlatformCoupon::DISCOUNT_PERCENTAGE) {
            $discount = $subtotal * ((float) $coupon->discount_value / 100);
            if ($coupon->max_discount_amount !== null) {
                $discount = min($discount, (float) $coupon->max_discount_amount);
            }

            return round(min($discount, $subtotal), 2);
        }

        return round(min((float) $coupon->discount_value, $subtotal), 2);
    }

    /** @return array{isValid: bool, reason: string} */
    private function evaluateCleaningConstraints(PlatformCoupon $coupon, array $context): array
    {
        $constraints = $coupon->relationLoaded('constraints')
            ? $coupon->constraints
            : $coupon->constraints()->get();

        $grouped = $constraints->groupBy('constraint_type');
        $checks = [
            PlatformCouponConstraint::TYPE_PROPERTY => ['propertyType', 'property_type_not_supported'],
            PlatformCouponConstraint::TYPE_CLEANING_MODE => ['cleaningMode', 'cleaning_mode_not_supported'],
            PlatformCouponConstraint::TYPE_EVENT => ['eventType', 'event_type_not_supported'],
        ];

        foreach ($checks as $type => [$contextKey, $reason]) {
            $allowed = $grouped->get($type)?->pluck('constraint_value')->map(
                static fn (mixed $value): string => mb_strtolower(trim((string) $value))
            )->all() ?? [];

            if ($allowed === []) {
                continue;
            }

            $actual = mb_strtolower(trim((string) ($context[$contextKey] ?? '')));
            if ($actual === '' || ! in_array($actual, $allowed, true)) {
                return $this->invalid($reason);
            }
        }

        return ['isValid' => true, 'reason' => 'ok'];
    }

    /** @return array{isValid: false, reason: string} */
    private function invalid(string $reason): array
    {
        return ['isValid' => false, 'reason' => $reason];
    }
}
