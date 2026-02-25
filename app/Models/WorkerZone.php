<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WorkerZone extends Model
{
    protected $fillable = [
        'worker_id',
        'name',
        'polygon',
        'is_active',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function casts(): array
    {
        return [
            'polygon' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
