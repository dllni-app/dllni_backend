<?php

declare(strict_types=1);

namespace Modules\Supermarket\Data;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class SmCartItemData extends Data
{
    public function __construct(
        #[Exists('sm_carts', 'id')]
        public ?int $cartId,
        #[Exists('sm_products', 'id')]
        public ?int $productId,
        #[Numeric, Min(1)]
        public ?int $quantity,
        #[Numeric, Min(0)]
        public ?float $unitPrice,
    ) {}

    public function onlyModelAttributes(): array
    {
        return array_filter($this->toArray(), fn ($value) => $value !== null);
    }
}
