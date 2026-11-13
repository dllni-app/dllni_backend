<?php

declare(strict_types=1);

namespace Modules\Resturants\Data;

use Modules\Resturants\Models\Offer;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<Offer> */
final class OfferData extends Data
{
    use HasModelAttributes;

    protected static string $model = Offer::class;

    public function __construct(
        public ?int $restaurantId,
        public ?string $name,
        public ?string $discountType,
        public ?float $discountValue,
        public ?string $startsAt,
        public ?string $endsAt,
        public ?bool $isActive,
        public ?array $productIds = null,
        public ?string $description = null,
        public string $offerType = 'product_discount',
        public ?int $buyQuantity = null,
        public ?int $rewardQuantity = null,
        public ?string $rewardType = null,
        public ?float $rewardDiscountPercent = null,
        public ?bool $isRepeatable = null,
        public ?int $maxApplicationsPerOrder = null,
        public ?array $rewardProductIds = null,
        public ?bool $showUpcoming = null,
    ) {}
}