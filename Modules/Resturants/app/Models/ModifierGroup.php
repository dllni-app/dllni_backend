<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class ModifierGroup extends Model
{
    protected $fillable = [
        'restaurant_id',
        'name',
        'is_required',
        'min_selections',
        'max_selections',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'modifier_group_product')
            ->withTimestamps();
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(Modifier::class, 'modifier_group_id');
    }

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'min_selections' => 'integer',
            'max_selections' => 'integer',
        ];
    }
}
