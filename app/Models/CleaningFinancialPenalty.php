<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningFinancialPenalty extends Model
{
    public const SOURCE_DEPOSIT = 'deposit';

    public const SOURCE_DEBT = 'debt';

    public const SOURCE_MIXED = 'mixed';

    public const SOURCE_CUSTOMER_FEE = 'customer_fee';

    public const ROLE_WORKER = 'worker';

    public const ROLE_CUSTOMER = 'customer';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLEARED = 'cleared';

    public const STATUS_CANCELLED = 'cancelled';

    public const REVIEW_NEEDS_REVIEW = 'needs_review';

    public const REVIEW_REVIEWED = 'reviewed';

    protected $fillable = [
        'cleaning_booking_id',
        'worker_id',
        'customer_id',
        'penalized_role',
        'financial_transaction_id',
        'financial_source',
        'amount',
        'status',
        'review_status',
        'reviewed_by_admin_id',
        'reviewed_at',
        'cancelled_by_admin_id',
        'penalty_cancelled_at',
        'penalty_cancellation_note',
        'notes',
        'cancellation_reason_snapshot',
        'cancellation_offset_minutes',
        'applied_by_admin_id',
        'applied_at',
        'cleared_at',
    ];

    public function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cancellation_offset_minutes' => 'integer',
            'applied_at' => 'datetime',
            'cleared_at' => 'datetime',
            'reviewed_at' => 'datetime',
            'penalty_cancelled_at' => 'datetime',
        ];
    }

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function financialTransaction(): BelongsTo
    {
        return $this->belongsTo(CleaningDepositTransaction::class, 'financial_transaction_id');
    }

    public function appliedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_admin_id');
    }

    public function reviewedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by_admin_id');
    }

    public function cancelledByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by_admin_id');
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    public function isCancelled(): bool
    {
        return $this->status === self::STATUS_CANCELLED;
    }

    public function needsReview(): bool
    {
        return $this->review_status === self::REVIEW_NEEDS_REVIEW;
    }
}
