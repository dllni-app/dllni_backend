<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

final class CleaningWorkerDeposit extends Model
{
    use LogsActivity;

    protected $fillable = [
        'worker_id',
        'current_balance',
        'debt_balance',
        'deposited_total',
        'withdrawn_total',
        'admin_revenue_withdrawn_total',
        'minimum_required',
        'max_negative_balance',
        'is_active',
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

    public function transactions(): HasMany
    {
        return $this->hasMany(CleaningDepositTransaction::class, 'worker_id', 'worker_id');
    }

    protected static function booted(): void
    {
        self::saving(function (self $account): void {
            $financeEnabled = (bool) (CleaningDepositSetting::query()->value('is_enabled') ?? true);
            $indebtedness = max(0.0, (float) $account->debt_balance);
            $allowedDebtLimit = max(0.0, (float) $account->max_negative_balance);

            // The financial account stays active through the allowed indebtedness limit
            // and becomes inactive only after that limit is exceeded.
            $account->is_active = ! $financeEnabled || $indebtedness <= $allowedDebtLimit;
        });
    }

    protected function casts(): array
    {
        return [
            'current_balance' => 'decimal:2',
            'debt_balance' => 'decimal:2',
            'deposited_total' => 'decimal:2',
            'withdrawn_total' => 'decimal:2',
            'admin_revenue_withdrawn_total' => 'decimal:2',
            'minimum_required' => 'decimal:2',
            'max_negative_balance' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
