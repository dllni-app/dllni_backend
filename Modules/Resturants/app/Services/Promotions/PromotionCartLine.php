<?php

declare(strict_types=1);

namespace Modules\Resturants\Services\Promotions;

use InvalidArgumentException;

/** Prices are integer hundredths of the stored currency unit. */
final readonly class PromotionCartLine
{
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

    public function originalUnit(): int
    {
        return $this->regularBase + $this->modifierAmount;
    }

    public function baselineUnit(): int
    {
        return $this->baselineBase + $this->modifierAmount;
    }
}
