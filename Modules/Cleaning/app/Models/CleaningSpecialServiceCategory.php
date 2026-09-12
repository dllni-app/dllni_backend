<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CleaningSpecialServiceCategory extends Model
{
    protected $fillable = ['name', 'slug', 'sort_order', 'is_active'];

    public function services(): HasMany
    {
        return $this->hasMany(CleaningSpecialService::class);
    }

    public function casts(): array
    {
        return ['sort_order' => 'integer', 'is_active' => 'boolean'];
    }
}
