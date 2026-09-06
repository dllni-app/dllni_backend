<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class CleaningSpecialServiceEquipment extends Model
{
    protected $fillable = ['name', 'is_active'];

    public function specialServices(): BelongsToMany
    {
        return $this->belongsToMany(
            CleaningSpecialService::class,
            'cleaning_special_service_equipment_map',
        )->withTimestamps();
    }

    public function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
