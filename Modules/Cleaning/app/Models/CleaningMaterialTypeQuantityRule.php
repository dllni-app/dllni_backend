<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningMaterialTypeQuantityRule extends Model
{
    protected $fillable = [
        'cleaning_material_type_id', 'room_type', 'room_size', 'cleaning_mode',
        'quantity_per_room', 'is_active', 'requires_admin_resolution', 'migration_conflict',
    ];

    public function materialType(): BelongsTo
    {
        return $this->belongsTo(CleaningMaterialType::class, 'cleaning_material_type_id');
    }

    public function casts(): array
    {
        return [
            'quantity_per_room' => 'float',
            'is_active' => 'boolean',
            'requires_admin_resolution' => 'boolean',
            'migration_conflict' => 'array',
        ];
    }
}
