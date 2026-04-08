<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class SmModifierGroup extends Model
{
    protected $table = 'sm_modifier_groups';

    protected $fillable = [
        'store_id',
        'name',
        'is_required',
        'min_selections',
        'max_selections',
        'sort_order',
        'is_active',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(SmStore::class, 'store_id');
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(SmProduct::class, 'sm_modifier_group_product', 'modifier_group_id', 'product_id')
            ->withTimestamps();
    }

    public function modifiers(): HasMany
    {
        return $this->hasMany(SmModifier::class, 'modifier_group_id');
    }

    protected function casts(): array
    {
        return [
            'is_required' => 'boolean',
            'min_selections' => 'integer',
            'max_selections' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
