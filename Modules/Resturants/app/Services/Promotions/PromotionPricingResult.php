<?php

declare(strict_types=1);

namespace Modules\Resturants\Services\Promotions;

final readonly class PromotionPricingResult
{
    public function __construct(
        public array $lines,
        public int $originalSubtotal,
        public int $ordinaryDiscount,
        public int $promotionDiscount,
        public int $netSubtotal,
        public array $appliedPromotions,
        public array $progress,
        public array $invalidRewards,
        public string $fingerprint,
        public bool $boundedFallback,
    ) {}

    public static function money(int $minor): float
    {
        return $minor / 100;
    }

    public function amounts(): array
    {
        return [
            'originalSubtotal' => self::money($this->originalSubtotal),
            'ordinaryDiscount' => self::money($this->ordinaryDiscount),
            'promotionDiscount' => self::money($this->promotionDiscount),
            'productDiscount' => self::money($this->ordinaryDiscount + $this->promotionDiscount),
            'subtotal' => self::money($this->netSubtotal),
            'total' => self::money($this->netSubtotal),
        ];
    }

    public function linePayload(int $lineId): array
    {
        $line = $this->lines[$lineId];

        return [
            'originalTotalPrice' => self::money($line['originalTotal']),
            'totalPrice' => self::money($line['netTotal']),
            'ordinaryDiscountAmount' => self::money($line['ordinaryDiscount']),
            'promotionDiscountAmount' => self::money($line['promotionDiscount']),
            'rewardQuantity' => $line['rewardQuantity'],
            'freeQuantity' => $line['freeQuantity'],
            'invalidRewardQuantity' => $line['invalidRewardQuantity'],
            'allocations' => array_map(static fn (array $segment): array => [
                'offerId' => $segment['offerId'],
                'role' => $segment['role'],
                'quantity' => $segment['quantity'],
                'unitPrice' => $segment['role'] === 'invalid_reward' ? null : self::money($segment['netUnit']),
                'originalUnitPrice' => self::money($segment['originalUnit']),
                'promotionDiscountAmount' => self::money($segment['promotionDiscount'] * $segment['quantity']),
                'freeQuantity' => $segment['free'] ? $segment['quantity'] : 0,
            ], $line['allocations']),
        ];
    }

    public function promotionsPayload(): array
    {
        return array_values(array_map(static fn (array $application): array => [
            ...$application['rule'],
            'applications' => $application['applications'],
            'rewardQuantity' => $application['rewardQuantity'],
            'freeQuantity' => $application['freeQuantity'],
            'discountAmount' => self::money($application['discount']),
            'incrementalSavings' => self::money($application['gain']),
            'netEligibleRevenue' => self::money($application['netRevenue']),
        ], $this->appliedPromotions));
    }

    public function requiresReview(): bool
    {
        return $this->invalidRewards !== [];
    }

    public function hasAdvancedPricing(): bool
    {
        return $this->appliedPromotions !== [] || $this->invalidRewards !== [];
    }
}
