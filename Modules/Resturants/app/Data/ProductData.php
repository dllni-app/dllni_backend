<?php

declare(strict_types=1);

namespace Modules\Resturants\Data;

use Modules\Resturants\Models\Product;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<Product> */
final class ProductData extends Data
{
    use HasModelAttributes;

    protected static string $model = Product::class;

    public function __construct(
        public ?int $restaurantId,
        public ?int $categoryId,
        public ?int $masterProductId,
        public ?string $name,
        public ?string $slug,
        public ?string $description,
        public ?float $price,
        public ?float $discountedPrice,
        public ?bool $isAvailable,
        public ?int $stockQuantity,
        public ?int $lowStockThreshold,
        public ?int $preparationTime,
        public ?bool $isFeatured,
    ) {}
}
