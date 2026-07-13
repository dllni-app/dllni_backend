<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

final class CleaningDepositTransaction extends Model
{
    use LogsActivity;

    public const AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX = 'automatic_admin_commission:';

    /** @var list<string> */
    public const PUBLIC_TYPES = ['deposit', 'debt', 'settlement', 'refund'];

    protected $fillable = [
        'worker_id',
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

    public function createdByAdmin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_admin_id');
    }

    public function scopePubliclyVisible(Builder $query): Builder
    {
        return $query->whereIn('type', self::PUBLIC_TYPES);
    }

    public function scopeForPublicType(Builder $query, string $type): Builder
    {
        if (! in_array($type, self::PUBLIC_TYPES, true)) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('type', $type);
    }

    public function publicType(): string
    {
        return self::normalizePublicType((string) $this->type, (float) $this->amount);
    }

    public function publicAmount(): float
    {
        return abs((float) $this->amount);
    }

    /**
     * Kept for compatibility while historical records are normalized by the
     * migration that reduces the ledger to the four supported types.
     */
    public static function normalizePublicType(string $type, float $amount = 0): string
    {
        return match ($type) {
            'admin_fee' => 'debt',
            'withdrawal' => 'refund',
            'adjustment' => $amount < 0 ? 'refund' : 'deposit',
            'deposit', 'debt', 'settlement', 'refund' => $type,
            default => $type,
        };
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
