<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use App\Models\MasterProduct;
use Database\Factories\ProductFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Resturants\Traits\FilterQueries\ProductFilterQuery;

final class Product extends Model
{
    use HasFactory;
    use ProductFilterQuery;

    protected $fillable = [
        'restaurant_id',
        'category_id',
        'master_product_id',
        'name',
        'slug',
        'description',
        'price',
        'discounted_price',
        'is_available',
        'unavailable_until',
        'availability_note',
        'stock_quantity',
        'low_stock_threshold',
        'preparation_time',
        'is_featured',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function masterProduct(): BelongsTo
    {
        return $this->belongsTo(MasterProduct::class, 'master_product_id');
    }

    public function modifierGroups(): BelongsToMany
    {
        return $this->belongsToMany(ModifierGroup::class, 'modifier_group_product')
            ->withTimestamps();
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function substitutions(): HasMany
    {
        return $this->hasMany(RestaurantProductSubstitution::class, 'product_id');
    }

    public function inventoryItems(): BelongsToMany
    {
        return $this->belongsToMany(InventoryItem::class, 'inventory_item_product')
            ->withPivot('quantity_used')
            ->withTimestamps();
    }

    public function scopeLowStock($query, mixed $value = true)
    {
        if (! filter_var($value, FILTER_VALIDATE_BOOLEAN)) {
            return $query;
        }

        return $query->whereColumn('stock_quantity', '<=', 'low_stock_threshold');
    }

    public function isAvailableNow(): bool
    {
        if ($this->is_available) {
            return true;
        }

        return $this->unavailable_until !== null && now()->greaterThan($this->unavailable_until);
    }

    public function availabilityMode(): string
    {
        if ($this->isAvailableNow()) {
            return 'available';
        }

        if ($this->unavailable_until !== null && now()->lessThanOrEqualTo($this->unavailable_until)) {
            return 'sold_out_today';
        }

        return 'manual_unavailable';
    }

    protected static function newFactory(): ProductFactory
    {
        return ProductFactory::new();
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'discounted_price' => 'decimal:2',
            'is_available' => 'boolean',
            'unavailable_until' => 'datetime',
            'availability_note' => 'string',
            'stock_quantity' => 'integer',
            'low_stock_threshold' => 'integer',
            'preparation_time' => 'integer',
            'is_featured' => 'boolean',
        ];
    }
}
