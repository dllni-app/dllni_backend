<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Resturants\Enums\InventoryLogType;

final class InventoryLog extends Model
{
    protected $fillable = [
        'product_id',
        'type',
        'quantity_change',
        'quantity_after',
        'note',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    protected function casts(): array
    {
        return [
            'type' => InventoryLogType::class,
            'quantity_change' => 'integer',
            'quantity_after' => 'integer',
        ];
    }
}
