<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CleaningMaterialType extends Model
{
    protected $fillable = ['name', 'cleaning_material_unit_id', 'price_per_unit', 'is_active'];

    public function unit(): BelongsTo
    {
        return $this->belongsTo(CleaningMaterialUnit::class, 'cleaning_material_unit_id');
    }

    public function materials(): HasMany
    {
        return $this->hasMany(CleaningMaterial::class);
    }

    public function quantityRules(): HasMany
    {
        return $this->hasMany(CleaningMaterialTypeQuantityRule::class);
    }

    public function casts(): array
    {
        return ['price_per_unit' => 'float', 'is_active' => 'boolean'];
    }
}
