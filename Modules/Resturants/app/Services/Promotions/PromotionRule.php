<?php

declare(strict_types=1);

namespace Modules\Resturants\Services\Promotions;

use DateTimeInterface;
use Modules\Resturants\Enums\OfferRewardType;

final readonly class PromotionRule
{
    /**
     * rewardPercentBps: 10000 = 100%; 5000 = 50%.
     * @param list<int> $qualifyingIds
     * @param list<int> $rewardIds
     */
    public function __construct(
        public int $id,
        public int $restaurantId,
        public int $version,
        public string $name,
        public int $buyQuantity,
        public int $rewardQuantity,
        public OfferRewardType $rewardType,
        public int $rewardPercentBps,
        public array $qualifyingIds,
        public array $rewardIds = [],
        public bool $repeatable = false,
        public ?int $maxApplications = null,
        public bool $active = true,
        public ?DateTimeInterface $startsAt = null,
        public ?DateTimeInterface $endsAt = null,
        public ?array $availableRewardIds = null,
    ) {}

    public function isValidAt(int $restaurantId, DateTimeInterface $at): bool
    {
        return $this->id > 0 && $this->version > 0 && $this->restaurantId === $restaurantId
            && $this->active && ($this->startsAt === null || $this->startsAt <= $at)
            && ($this->endsAt === null || $this->endsAt >= $at)
            && $this->buyQuantity >= 1 && $this->buyQuantity <= 100
            && $this->rewardQuantity >= 1 && $this->rewardQuantity <= 100
            && $this->rewardPercentBps >= 1 && $this->rewardPercentBps <= 10000
            && ($this->maxApplications === null || ($this->maxApplications >= 1 && $this->maxApplications <= 100))
            && $this->qualifyingIds !== [] && count($this->qualifyingIds) <= 100
            && ($this->rewardType !== OfferRewardType::SpecificProducts
                || ($this->rewardIds !== [] && count($this->rewardIds) <= 100
                    && array_intersect($this->qualifyingIds, $this->rewardIds) === []));
    }

    public function limit(): int
    {
        return $this->repeatable ? ($this->maxApplications ?? PHP_INT_MAX) : 1;
    }

    public function rewardDiscount(int $base): int
    {
        return intdiv($base * $this->rewardPercentBps + 5000, 10000);
    }

    public function snapshot(): array
    {
        $qualifying = array_values(array_unique($this->qualifyingIds));
        $rewards = array_values(array_unique($this->rewardIds));
        sort($qualifying, SORT_NUMERIC);
        sort($rewards, SORT_NUMERIC);
        $availableRewards = $this->availableRewardIds ?? $rewards;
        sort($availableRewards, SORT_NUMERIC);

        return [
            'offerId' => $this->id,
            'restaurantId' => $this->restaurantId,
            'ruleVersion' => $this->version,
            'name' => $this->name,
            'offerType' => 'buy_x_get_y',
            'buyQuantity' => $this->buyQuantity,
            'rewardQuantity' => $this->rewardQuantity,
            'rewardType' => $this->rewardType->value,
            'rewardDiscountPercent' => $this->rewardPercentBps / 100,
            'productIds' => $qualifying,
            'rewardProductIds' => $rewards,
            'availableRewardProductIds' => $availableRewards,
            'isRepeatable' => $this->repeatable,
            'maxApplicationsPerOrder' => $this->maxApplications,
            'startsAt' => $this->startsAt?->format(DATE_ATOM),
            'endsAt' => $this->endsAt?->format(DATE_ATOM),
        ];
    }
}