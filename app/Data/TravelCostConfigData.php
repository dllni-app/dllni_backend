<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\TravelCostConfig;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<TravelCostConfig> */
final class TravelCostConfigData extends Data
{
    use HasModelAttributes;

    protected static string $model = TravelCostConfig::class;

    public function __construct(
        public ?string $name,
        public ?float $maxKm,
        public ?float $costPerKm,
        public ?float $fixedFee,
        public ?bool $isActive,
    ) {}
}
