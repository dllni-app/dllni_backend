<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Cleaning\Models\CleaningBooking;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

final class CleaningDepositTransaction extends Model
{
    use LogsActivity;

    protected $fillable = [
        'worker_id',
        'cleaning_booking_id',
        'created_by_admin_id',
        'type',
        'amount',
        'debt_settled_amount',
        'balance_before',
        'balance_after',
        'reference',
        'notes',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function cleaningBooking(): BelongsTo
    {
        return $this->belongsTo(CleaningBooking::class);
    }

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    protected function casts(): array
    {
        return [
            'type' => 'string',
            'amount' => 'decimal:2',
            'debt_settled_amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }
}
