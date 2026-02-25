<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MasterProductUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class MasterProduct extends Model
{
    protected $fillable = [
        'name',
        'barcode',
        'unit',
        'brand',
        'description',
        'is_active',
    ];

    public function aliases(): HasMany
    {
        return $this->hasMany(MasterProductAlias::class);
    }

    public function casts(): array
    {
        return [
            'unit' => MasterProductUnit::class,
            'is_active' => 'boolean',
        ];
    }
}
