<?php

declare(strict_types=1);

namespace Modules\Resturants\Data;

use Modules\Resturants\Models\Category;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<Category> */
final class CategoryData extends Data
{
    use HasModelAttributes;

    protected static string $model = Category::class;

    public function __construct(
        public ?int $restaurantId,
        public ?string $name,
        public ?string $slug,
        public ?int $sortOrder,
    ) {}
}
