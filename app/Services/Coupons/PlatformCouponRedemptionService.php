<?php

declare(strict_types=1);

namespace App\Services\Coupons;

use App\Models\PlatformCoupon;
use App\Models\PlatformCouponRedemption;
use App\Services\MerchantOrderFinancialSnapshotService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlatformCouponRedemptionService
{
    public function __construct(
        private readonly PlatformCouponEligibilityService $eligibility,
        private readonly MerchantOrderFinancialSnapshotService $financials,
    ) {}

    /** @return array{coupon: PlatformCoupon, discount: float}|null */
    public function preview(
        int $userId,
        string $section,
        ?string $couponCode,
        float $subtotal,
        array $context = [],
        bool $required = false,
    ): ?array {
        return $this->resolve($userId, $section, $couponCode, $subtotal, $context, false, $required);
    }

    /** @return array{coupon: PlatformCoupon, discount: float}|null */
    public function quoteForPlacement(
        int $userId,
        string $section,
        ?string $couponCode,
        float $subtotal,
        array $context = [],
        bool $required = false,
    ): ?array {
        return $this->resolve($userId, $section, $couponCode, $subtotal, $context, true, $required);
    }
    public function record(
        PlatformCoupon $coupon,
        int $userId,
        string $section,
        float $subtotal,
        float $discount,
        Model $order,
    ): PlatformCouponRedemption {
        return DB::transaction(function () use ($coupon, $userId, $section, $subtotal, $discount, $order): PlatformCouponRedemption {
            $lockedCoupon = PlatformCoupon::query()
                ->lockForUpdate()
                ->findOrFail($coupon->getKey());

            $this->assertUsageStillAvailable($lockedCoupon, $userId);

            $order->forceFill([
                'platform_coupon_id' => $lockedCoupon->id,
                'platform_coupon_code' => $lockedCoupon->code,
            ])->save();

            $funding = in_array($section, [
                PlatformCoupon::SECTION_RESTAURANT,
                PlatformCoupon::SECTION_SUPERMARKET,
            ], true)
                ? $this->financials->applyPlatformCoupon($order, $lockedCoupon, $discount)
                : [
                    'funding_type' => null,
                    'platform_funded_amount' => 0.0,
                    'merchant_funded_amount' => 0.0,
                    'funding_snapshot' => [
                        'version' => 1,
                        'source' => 'section_uses_existing_financial_rule',
                        'section' => $section,
                    ],
                ];

            $redemption = PlatformCouponRedemption::query()->create([
                'platform_coupon_id' => $lockedCoupon->id,
                'user_id' => $userId,
                'section' => $section,
                'order_type' => $order->getMorphClass(),
                'order_id' => $order->getKey(),
                'coupon_code' => $lockedCoupon->code,
                'subtotal' => $subtotal,
                'discount_amount' => $discount,
                'funding_type' => $funding['funding_type'],
                'platform_funded_amount' => $funding['platform_funded_amount'],
                'merchant_funded_amount' => $funding['merchant_funded_amount'],
                'funding_snapshot' => $funding['funding_snapshot'],
                'redeemed_at' => now(),
            ]);

            $lockedCoupon->increment('used_count');

            return $redemption;
        });
    }

    public function reverseForOrder(Model $order, string $reason, ?int $actorUserId = null): ?PlatformCouponRedemption
    {
        return DB::transaction(function () use ($order, $reason, $actorUserId): ?PlatformCouponRedemption {
            $orderTypes = array_values(array_unique([$order->getMorphClass(), $order::class]));

            $redemption = PlatformCouponRedemption::query()
                ->whereIn('order_type', $orderTypes)
                ->where('order_id', $order->getKey())
                ->whereNull('reversed_at')
                ->lockForUpdate()
                ->first();

            if (! $redemption instanceof PlatformCouponRedemption) {
                return null;
            }

            $coupon = PlatformCoupon::query()->lockForUpdate()->find($redemption->platform_coupon_id);

            $redemption->update([
                'reversed_at' => now(),
                'reversed_by_user_id' => $actorUserId,
                'reversal_reason' => trim($reason),
            ]);

            if ($coupon instanceof PlatformCoupon && (int) $coupon->used_count > 0) {
                $coupon->decrement('used_count');
            }

            $this->financials->markReversed($order, $reason);

            activity('platform_coupons')
                ->performedOn($order)
                ->withProperties([
                    'platform_coupon_redemption_id' => $redemption->id,
                    'platform_coupon_id' => $redemption->platform_coupon_id,
                    'reason' => trim($reason),
                    'actor_user_id' => $actorUserId,
                ])
                ->log('platform_coupon_redemption_reversed');

            return $redemption->fresh();
        });
    }
    private function assertUsageStillAvailable(PlatformCoupon $coupon, int $userId): void
    {
        if ($coupon->total_usage_limit !== null && (int) $coupon->used_count >= (int) $coupon->total_usage_limit) {
            throw ValidationException::withMessages(['couponCode' => ['global_usage_limit_reached']]);
        }

        if ($coupon->per_user_usage_limit === null) {
            return;
        }

        $activeUserRedemptions = PlatformCouponRedemption::query()
            ->where('platform_coupon_id', $coupon->id)
            ->where('user_id', $userId)
            ->whereNull('reversed_at')
            ->count();

        if ($activeUserRedemptions >= (int) $coupon->per_user_usage_limit) {
            throw ValidationException::withMessages(['couponCode' => ['user_usage_limit_reached']]);
        }
    }

    /** @return array{coupon: PlatformCoupon, discount: float}|null */
    private function resolve(
        int $userId,
        string $section,
        ?string $couponCode,
        float $subtotal,
        array $context,
        bool $lock,
        bool $required,
    ): ?array {
        $normalizedCode = mb_strtoupper(trim((string) $couponCode));
        if ($normalizedCode === '') {
            return null;
        }

        $query = PlatformCoupon::query()->with('constraints')->whereRaw('UPPER(code) = ?', [$normalizedCode]);
        if ($lock) {
            $query->lockForUpdate();
        }

        $coupon = $query->first();
        if (! $coupon) {
            if ($required) {
                throw ValidationException::withMessages(['couponCode' => ['not_found']]);
            }

            return null;
        }

        $result = $this->eligibility->evaluate($coupon, $userId, $section, $subtotal, $context);
        if (! $result['isValid']) {
            throw ValidationException::withMessages(['couponCode' => [$result['reason']]]);
        }

        return [
            'coupon' => $coupon,
            'discount' => $this->eligibility->calculateDiscount($coupon, $subtotal),
        ];
    }
}
