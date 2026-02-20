<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SmStoreHours extends Model
{
    protected $table = 'sm_store_hours';

    protected $fillable = [
        'store_id',
        'day_of_week',
        'opens_at',
        'closes_at',
        'is_closed',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(SmStore::class, 'store_id');
    }

    protected function casts(): array
    {
        return [
            'is_closed' => 'boolean',
        ];
    }
}
