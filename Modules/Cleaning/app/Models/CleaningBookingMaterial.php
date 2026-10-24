<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CleaningBookingMaterial extends Model
{
    protected $fillable = [
        'cleaning_booking_id',
        'cleaning_material_id',
        'cleaning_material_type_id',
        'cleaning_material_unit_id',
        'quantity',
        'unit_price',
        'total_price',
        'inventory_status',
        'used_quantity', 'returned_quantity', 'damaged_quantity', 'lost_quantity',
        'reconciled_at', 'reconciled_by_user_id',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function material(): BelongsTo
    {
        return $this->belongsTo(CleaningMaterial::class, 'cleaning_material_id');
    }

    public function materialType(): BelongsTo
    {
        return $this->belongsTo(CleaningMaterialType::class, 'cleaning_material_type_id');
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(CleaningMaterialUnit::class, 'cleaning_material_unit_id');
    }

    public function inventoryMovements(): HasMany
    {
        return $this->hasMany(CleaningMaterialInventoryMovement::class);
    }

    public function casts(): array
    {
        return [
            'quantity' => 'float', 'unit_price' => 'float', 'total_price' => 'float',
            'used_quantity' => 'float', 'returned_quantity' => 'float',
            'damaged_quantity' => 'float', 'lost_quantity' => 'float', 'reconciled_at' => 'datetime',
        ];
    }
}
