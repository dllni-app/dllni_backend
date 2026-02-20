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
    ) {}
}
