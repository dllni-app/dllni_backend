<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CleaningMaterial extends Model
{
    protected $fillable = ['name', 'cleaning_material_type_id', 'stock_quantity', 'low_stock_threshold', 'is_active'];

    public function materialType(): BelongsTo
    {
        return $this->belongsTo(CleaningMaterialType::class, 'cleaning_material_type_id');
    }

    public function quantityRules(): HasMany
    {
        return $this->hasMany(CleaningMaterialQuantityRule::class);
    }

    public function bookingMaterials(): HasMany
    {
        return $this->hasMany(CleaningBookingMaterial::class);
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(CleaningMaterialInventoryMovement::class);
    }

    public function casts(): array
    {
        return [
            'stock_quantity' => 'float',
            'low_stock_threshold' => 'float',
            'is_active' => 'boolean',
        ];
    }

    public function isLowStock(): bool
    {
        return (float) $this->stock_quantity <= (float) $this->low_stock_threshold;
    }
}
