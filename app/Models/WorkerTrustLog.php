<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WorkerTrustLog extends Model
{
    protected $fillable = [
        'worker_id',
        'reason',
        'score_delta',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function casts(): array
    {
        return [
            'score_delta' => 'integer',
        ];
    }
}
