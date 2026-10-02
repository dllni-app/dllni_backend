<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RecipeAlias extends Model
{
    protected $fillable = ['recipe_id', 'alias'];

    public function recipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class);
    }
}
