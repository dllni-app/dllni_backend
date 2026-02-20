<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\MasterProductUnit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RecipeIngredient extends Model
{
    protected $fillable = [
        'recipe_id',
        'master_product_id',
        'quantity',
        'unit',
        'is_optional',
    ];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }

    public function masterProduct(): BelongsTo
    {
        return $this->belongsTo(MasterProduct::class);
    }

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:4',
            'unit' => MasterProductUnit::class,
            'is_optional' => 'boolean',
        ];
    }
}
