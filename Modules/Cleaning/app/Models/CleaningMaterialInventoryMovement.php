<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningMaterialInventoryMovement extends Model
{
    public const RESERVED = 'reserved';
    public const RELEASED = 'released';
    public const CONSUMED = 'consumed';
    public const ADJUSTED = 'adjusted';

    protected $fillable = [
        'cleaning_material_id',
        'cleaning_booking_material_id',
        'movement_type',
        'quantity_delta',
        'reference_type',
        'reference_id',
        'note',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(CleaningMaterial::class, 'cleaning_material_id');
    }

    public function bookingMaterial(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingMaterial::class, 'cleaning_booking_material_id');
    }

    public function casts(): array
    {
        return ['quantity_delta' => 'float'];
    }
}
