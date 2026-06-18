<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Cleaning\Models\CleaningBooking;

final class WorkerTrustLog extends Model
{
    protected $fillable = [
        'worker_id',
        'cleaning_booking_id',
        'reason',
        'score_delta',
        'score_before',
        'score_after',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function cleaningBooking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class);
    }

    public function casts(): array
    {
        return [
            'score_delta' => 'integer',
            'score_before' => 'integer',
            'score_after' => 'integer',
        ];
    }
}
