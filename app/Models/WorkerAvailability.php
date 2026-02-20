<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\AvailabilityType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class WorkerAvailability extends Model
{
    protected $table = 'worker_availability';

    protected $fillable = [
        'worker_id',
        'availability_date',
        'availability_type',
        'start_time',
        'end_time',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    protected function casts(): array
    {
        return [
            'availability_date' => 'date',
            'availability_type' => AvailabilityType::class,
        ];
    }
}
