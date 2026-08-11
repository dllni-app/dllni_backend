<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Cleaning\Models\CleaningNeighborhood;

final class WorkerZone extends Model
{
    protected $fillable = [
        'worker_id',
        'neighborhood_id',
        'name',
        'polygon',
        'is_active',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function neighborhood(): BelongsTo
    {
        return $this->belongsTo(CleaningNeighborhood::class, 'neighborhood_id');
    }

    public function casts(): array
    {
        return [
            'neighborhood_id' => 'integer',
            'polygon' => 'array',
            'is_active' => 'boolean',
        ];
    }
}
