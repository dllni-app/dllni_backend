<?php

declare(strict_types=1);

namespace Modules\Resturants\Data;

use Modules\Resturants\Models\PromoCode;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<PromoCode> */
final class PromoCodeData extends Data
{
    use HasModelAttributes;

    protected static string $model = PromoCode::class;

    public function __construct(
        public ?int $restaurantId,
        public ?string $code,
        public ?string $discountType,
        public ?float $discountValue,
        public ?float $minOrderAmount,
        public ?int $usageLimit,
        public ?int $usageCount,
        public ?string $startsAt,
        public ?string $endsAt,
        public ?bool $isActive,
    ) {}
}
