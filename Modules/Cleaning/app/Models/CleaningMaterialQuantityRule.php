<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningMaterialQuantityRule extends Model
{
    protected $fillable = [
        'cleaning_material_id',
        'room_type',
        'room_size',
        'cleaning_mode',
        'quantity_per_room',
        'is_active',
    ];

    public function material(): BelongsTo
    {
        return $this->belongsTo(CleaningMaterial::class, 'cleaning_material_id');
    }

    public function casts(): array
    {
        return ['quantity_per_room' => 'float', 'is_active' => 'boolean'];
    }
}
