<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\WorkerCustomerRatingType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class WorkerCustomerRating extends Model
{
    protected $fillable = [
        'booking_id',
        'booking_type',
        'worker_id',
        'customer_id',
        'rating_type',
        'rating',
        'comment',
    ];

    public function booking(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'booking_type', 'booking_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function casts(): array
    {
        return [
            'rating_type' => WorkerCustomerRatingType::class,
        ];
    }
}
