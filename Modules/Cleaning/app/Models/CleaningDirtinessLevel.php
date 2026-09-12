<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

final class CleaningDirtinessLevel extends Model
{
    protected $fillable = ['name', 'slug', 'price_multiplier', 'sort_order', 'is_active'];

    public function services(): BelongsToMany
    {
        return $this->belongsToMany(CleaningSpecialService::class, 'cleaning_special_service_dirtiness_levels')->withTimestamps();
    }

    public function casts(): array
    {
        return ['price_multiplier' => 'float', 'sort_order' => 'integer', 'is_active' => 'boolean'];
    }
}
