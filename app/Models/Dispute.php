<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\DisputeCategory;
use App\Enums\DisputeResolution;
use App\Enums\DisputeStatus;
use App\Observers\DisputeObserver;
use App\Traits\FilterQueries\DisputeFilterQuery;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Delivery\Models\DeliveryDriverTrustLog;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

#[ObservedBy([DisputeObserver::class])]
final class Dispute extends Model implements HasMedia
{
    use DisputeFilterQuery;
    use InteractsWithMedia;

    protected $fillable = [
        'booking_id',
        'booking_type',
        'ticket_number',
        'description',
        'category',
        'status',
        'resolution',
        'worker_earnings_frozen',
        'financial_penalty_worker_id',
        'financial_penalty_amount',
        'financial_penalty_notes',
        'financial_penalty_transaction_id',
        'financial_penalty_applied_by',
        'financial_penalty_applied_at',
    ];

    public function booking(): MorphTo
    {
        return $this->morphTo(__FUNCTION__, 'booking_type', 'booking_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(DisputeMessage::class);
    }

    public function trustLogs(): HasMany
    {
        return $this->hasMany(DeliveryDriverTrustLog::class, 'related_dispute_id');
    }

    public function financialPenaltyWorker(): BelongsTo
    {
        return $this->belongsTo(Worker::class, 'financial_penalty_worker_id');
    }

    public function financialPenaltyTransaction(): BelongsTo
    {
        return $this->belongsTo(CleaningDepositTransaction::class, 'financial_penalty_transaction_id');
    }

    public function financialPenaltyAppliedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'financial_penalty_applied_by');
    }

    public function casts(): array
    {
        return [
            'category' => DisputeCategory::class,
            'status' => DisputeStatus::class,
            'resolution' => DisputeResolution::class,
            'worker_earnings_frozen' => 'boolean',
            'financial_penalty_amount' => 'decimal:2',
            'financial_penalty_applied_at' => 'datetime',
        ];
    }
}
