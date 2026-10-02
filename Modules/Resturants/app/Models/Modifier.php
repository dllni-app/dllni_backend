<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class Modifier extends Model
{
    protected $fillable = [
        'modifier_group_id',
        'name',
        'price',
        'sort_order',
    ];

    public function modifierGroup(): BelongsTo
    {
        return $this->belongsTo(ModifierGroup::class);
    }

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'sort_order' => 'integer',
        ];
    }
}
