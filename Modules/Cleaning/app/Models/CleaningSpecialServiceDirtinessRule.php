<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningSpecialServiceDirtinessRule extends Model
{
    protected $fillable = ['cleaning_special_service_id', 'dirtiness_level', 'price_multiplier', 'is_active'];

    public function specialService(): BelongsTo
    {
        return $this->belongsTo(CleaningSpecialService::class, 'cleaning_special_service_id');
    }

    public function casts(): array
    {
        return ['price_multiplier' => 'float', 'is_active' => 'boolean'];
    }
}
