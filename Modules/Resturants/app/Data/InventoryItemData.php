<?php

declare(strict_types=1);

namespace Modules\Resturants\Data;

use Modules\Resturants\Models\InventoryItem;
use Mrmarchone\LaravelAutoCrud\Traits\HasModelAttributes;
use Spatie\LaravelData\Data;

/** @var class-string<InventoryItem> */
final class InventoryItemData extends Data
{
    use HasModelAttributes;

    protected static string $model = InventoryItem::class;

    public function __construct(
        public ?int $restaurantId,
        public ?string $name,
        public ?string $unit,
        public ?float $quantity,
        public ?float $minimumLimit,
        public ?float $unitCost,
        public ?array $productIds = null,
    ) {}
}
