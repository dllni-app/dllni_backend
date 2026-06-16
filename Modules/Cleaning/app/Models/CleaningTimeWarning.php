<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Observers\CleaningTimeWarningObserver;
use Modules\Cleaning\Traits\FilterQueries\CleaningTimeWarningFilterQuery;

#[ObservedBy([CleaningTimeWarningObserver::class])]
final class CleaningTimeWarning extends Model
{
    use CleaningTimeWarningFilterQuery;

    protected $fillable = [
        'booking_id',
        'booking_type',
        'customer_response',
        'worker_response',
        'sent_at',
        'customer_responded_at',
        'worker_responded_at',
        'additional_minutes',
        'quoted_amount',
        'quoted_currency',
        'price_applied_at',
        'worker_reject_message',
    ];

    public function booking(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'booking_type', 'booking_id');
    }

    public function casts(): array
    {
        return [
            'customer_response' => CleaningTimeWarningResponse::class,
            'worker_response' => CleaningTimeWarningResponse::class,
            'sent_at' => 'datetime',
            'customer_responded_at' => 'datetime',
            'worker_responded_at' => 'datetime',
            'quoted_amount' => 'decimal:2',
            'price_applied_at' => 'datetime',
        ];
    }
}
