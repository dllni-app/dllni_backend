<?php

declare(strict_types=1);

namespace Modules\Delivery\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class DeliveryOrderEvent extends Model
{
    protected $fillable = [
        'order_id',
        'actor_type',
        'actor_id',
        'from_status',
        'to_status',
        'note',
        'payload',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(DeliveryOrder::class, 'order_id');
    }

    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
