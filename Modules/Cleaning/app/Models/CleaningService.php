<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Cleaning\Enums\ServiceCategory;

final class CleaningService extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'category',
        'description',
        'is_active',
    ];

    public function pricing(): HasMany
    {
        return $this->hasMany(ServicePricing::class);
    }

    protected function casts(): array
    {
        return [
            'category' => ServiceCategory::class,
            'is_active' => 'boolean',
        ];
    }
}
