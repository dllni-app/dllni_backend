<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SmModifier extends Model
{
    protected $table = 'sm_modifiers';

    protected $fillable = [
        'modifier_group_id',
        'name',
        'price',
        'sort_order',
        'is_available',
    ];

    public function modifierGroup(): BelongsTo
    {
        return $this->belongsTo(SmModifierGroup::class, 'modifier_group_id');
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sort_order' => 'integer',
            'is_available' => 'boolean',
        ];
    }
}
