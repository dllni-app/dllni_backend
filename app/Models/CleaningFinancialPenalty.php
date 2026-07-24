<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Cleaning\Models\CleaningBooking;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

final class CleaningFinancialPenalty extends Model
{
    use LogsActivity;

    public const SOURCE_DEPOSIT = 'deposit';

    public const SOURCE_DEBT = 'debt';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_CLEARED = 'cleared';

    protected $fillable = [
        'cleaning_booking_id',
        'worker_id',
        'financial_transaction_id',
        'financial_source',
        'amount',
        'status',
        'notes',
        'cancellation_reason_snapshot',
        'cancellation_offset_minutes',
        'applied_by_admin_id',
        'applied_at',
        'cleared_at',
    ];

    public function booking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class, 'cleaning_booking_id');
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function financialTransaction(): BelongsTo
    {
        return $this->belongsTo(CleaningDepositTransaction::class, 'financial_transaction_id');
    }

    public function appliedByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'applied_by_admin_id');
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'cancellation_offset_minutes' => 'integer',
            'applied_at' => 'datetime',
            'cleared_at' => 'datetime',
        ];
    }
}
