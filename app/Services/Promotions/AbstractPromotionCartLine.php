<?php

declare(strict_types=1);

namespace App\Services\Promotions;

use InvalidArgumentException;

/** Prices are integer hundredths of the stored currency unit. */
abstract readonly class AbstractPromotionCartLine
{
    // The restaurantId parameter retains its legacy name for named-argument and pricing-fingerprint compatibility.
    // Adapters supply a merchant ID scoped to their own commerce section.
    public function __construct(
        public int $id,
        public int $restaurantId,
        public int $productId,
        public int $quantity,
        public int $regularBase,
        public int $baselineBase,
        public int $modifierAmount = 0,
        public ?int $rewardOfferId = null,
        public bool $available = true,
        public string $signature = '',
    ) {
        if ($id < 1 || $restaurantId < 1 || $productId < 1 || $quantity < 1
            || $regularBase < 0 || $baselineBase < 0 || $baselineBase > $regularBase || $modifierAmount < 0) {
            throw new InvalidArgumentException('Invalid normalized promotion cart line.');
        }
    }

    final public function originalUnit(): int
    {
        return $this->regularBase + $this->modifierAmount;
    }

    final public function baselineUnit(): int
    {
        return $this->baselineBase + $this->modifierAmount;
    }
}
