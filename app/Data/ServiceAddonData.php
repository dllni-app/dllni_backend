<?php

declare(strict_types=1);

namespace App\Data;

use App\Models\ServiceAddon;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<ServiceAddon> */
final class ServiceAddonData extends Data
{
    use HasModelAttributes;

    protected static string $model = ServiceAddon::class;

    public function __construct(
        public ?string $name,
        public ?string $slug,
        public ?string $pricingType,
        public ?float $priceValue,
        public ?bool $isActive,
    ) {}
}
