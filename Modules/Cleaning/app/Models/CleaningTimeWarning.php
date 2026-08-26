<?php

declare(strict_types=1);

namespace Modules\Cleaning\Models;

use App\Models\Worker;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
        'cleaning_booking_session_id',
        'worker_id',
        'customer_response',
        'customer_message',
        'worker_response',
        'sent_at',
        'customer_responded_at',
        'worker_responded_at',
        'additional_minutes',
        'quoted_base_amount',
        'quoted_admin_margin_amount',
        'quoted_amount',
        'quoted_currency',
        'price_applied_at',
        'worker_reject_message',
    ];

    public function booking(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'booking_type', 'booking_id');
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CleaningBookingSession::class, 'cleaning_booking_session_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function casts(): array
    {
        return [
            'cleaning_booking_session_id' => 'integer',
            'worker_id' => 'integer',
            'customer_response' => CleaningTimeWarningResponse::class,
            'worker_response' => CleaningTimeWarningResponse::class,
            'sent_at' => 'datetime',
            'customer_responded_at' => 'datetime',
            'worker_responded_at' => 'datetime',
            'quoted_base_amount' => 'integer',
            'quoted_admin_margin_amount' => 'integer',
            'quoted_amount' => 'integer',
            'price_applied_at' => 'datetime',
        ];
    }
}
