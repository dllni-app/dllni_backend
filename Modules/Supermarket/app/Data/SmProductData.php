<?php

declare(strict_types=1);

namespace Modules\Supermarket\Data;

use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\Date;
use Spatie\LaravelData\Attributes\Validation\Exists;
use Spatie\LaravelData\Attributes\Validation\In;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Attributes\Validation\Numeric;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class SmProductData extends Data
{
    public function __construct(
        #[Exists('sm_stores', 'id')]
        public ?int $storeId,
        #[Exists('sm_categories', 'id')]
        public ?int $categoryId,
        #[Exists('master_products', 'id')]
        public ?int $masterProductId,
        #[Max(255)]
        public ?string $name,
        #[Max(255)]
        public ?string $barcode,
        #[In(['BarcodeScan', 'CatalogSearch', 'Manual', 'Template', 'BulkImport'])]
        public ?string $sourceType,
        public ?string $description,
        #[Numeric, Min(0)]
        public ?float $price,
        #[Numeric, Min(0)]
        public ?float $discountedPrice,
        #[Numeric, Min(0)]
        public ?int $stockQuantity,
        #[Numeric, Min(0)]
        public ?int $lowStockThreshold,
        #[Date]
        public ?string $expiresAt,
        #[BooleanType]
        public ?bool $isAvailable,
    ) {}

    public function onlyModelAttributes(): array
    {
        return array_filter($this->toArray(), fn ($v) => $v !== null);
    }
}
