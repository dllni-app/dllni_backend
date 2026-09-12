<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\Worker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class CleaningBookingMaterialKit extends Model
{
    public const PREPARING = 'preparing';
    public const READY = 'ready';
    public const RECEIVED = 'received';

    protected $fillable = [
        'cleaning_booking_id', 'status', 'prepared_at', 'prepared_by_id',
        'received_by_worker_id', 'received_at', 'notes',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function receivedByWorker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'received_by_worker_id');
    }

    public function casts(): array
    {
        return ['prepared_at' => 'datetime', 'received_at' => 'datetime'];
    }
}
