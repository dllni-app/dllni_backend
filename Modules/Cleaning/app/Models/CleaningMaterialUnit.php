<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CleaningMaterialUnit extends Model
{
    protected $fillable = ['name', 'code', 'symbol', 'is_active'];

    public function materialTypes(): HasMany
    {
        return $this->hasMany(CleaningMaterialType::class);
    }

    public function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
