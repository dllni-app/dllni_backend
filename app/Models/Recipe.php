<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Recipe extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'servings',
        'is_active',
    ];

    public function ingredients(): HasMany
    {
        return $this->hasMany(RecipeIngredient::class);
    }

    public function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
