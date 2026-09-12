<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class CleaningSpecialServiceEquipment extends Model
{
    protected $fillable = [
        'name', 'asset_code', 'status', 'buffer_before_minutes', 'buffer_after_minutes',
        'last_handed_over_at', 'last_returned_at', 'is_active',
    ];

    public function specialServices(): BelongsToMany
    {
        return $this->belongsToMany(
            CleaningSpecialService::class,
            'cleaning_special_service_equipment_map',
        )->withTimestamps();
    }

    public function casts(): array
    {
        return [
            'buffer_before_minutes' => 'integer',
            'buffer_after_minutes' => 'integer',
            'last_handed_over_at' => 'datetime',
            'last_returned_at' => 'datetime',
            'is_active' => 'boolean',
        ];
    }
}
