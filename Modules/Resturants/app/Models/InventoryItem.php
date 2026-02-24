<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Modules\Resturants\Traits\FilterQueries\InventoryItemFilterQuery;

final class InventoryItem extends Model
{
    use InventoryItemFilterQuery;

    protected $fillable = [
        'restaurant_id',
        'name',
        'unit',
        'quantity',
        'minimum_limit',
        'unit_cost',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'inventory_item_product')
            ->withPivot('quantity_used')
            ->withTimestamps();
    }

    public function scopeLowStock($query, mixed $value = true)
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return $query;
        }

        return $query->whereColumn('quantity', '<=', 'minimum_limit');
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'minimum_limit' => 'decimal:4',
            'unit_cost' => 'decimal:2',
        ];
    }
}
