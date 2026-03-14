<?php

declare(strict_types=1);

namespace Modules\Resturants\Data;

use Illuminate\Http\UploadedFile;
use Modules\Resturants\Models\Product;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<Product> */
final class ProductData extends Data
{
    use HasModelAttributes;

    protected static string $model = Product::class;

    /**
     * @param  array<int, UploadedFile>|null  $images
     */
    public function __construct(
        public ?int $restaurantId,
        public ?int $categoryId,
        public ?string $name,
        public ?string $description,
        public ?float $price,
        public ?float $discountedPrice,
        public ?bool $isAvailable,
        public ?int $stockQuantity,
        public ?int $lowStockThreshold,
        public ?int $preparationTime,
        public ?bool $isFeatured,
        public ?UploadedFile $primaryImage = null,
        public ?array $images = null,
    ) {}
}
