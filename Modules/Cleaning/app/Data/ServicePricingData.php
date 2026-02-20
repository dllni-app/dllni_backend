<?php

declare(strict_types=1);

namespace Modules\Cleaning\Data;

use Modules\Cleaning\Models\ServicePricing;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<ServicePricing> */
final class ServicePricingData extends Data
{
    use HasModelAttributes;

    protected static string $model = ServicePricing::class;

    public function __construct(
        public ?int $cleaningServiceId,
        public ?string $propertyType,
        public ?string $livingRoomSize,
        public ?float $basePrice,
        public ?float $pricePerSqm,
        public ?float $minHours,
    ) {}
}
