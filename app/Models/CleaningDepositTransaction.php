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
    public const PUBLIC_TYPES = ['deposit', 'commission', 'debt', 'settlement', 'refund'];

    protected $fillable = [
        'worker_id',
        'created_by_admin_id',
        'type',
        'amount',
        'debt_settled_amount',
        'balance_before',
        'balance_after',
        'debt_balance_before',
        'debt_balance_after',
        'reference',
        'notes',
    ];

    public static function normalizePublicType(string $type, float $amount = 0): string
    {
        return match ($type) {
            'admin_fee', 'commission' => 'commission',
            'withdrawal' => 'refund',
            'adjustment' => $amount < 0 ? 'refund' : 'deposit',
            'deposit', 'debt', 'settlement', 'refund' => $type,
            default => $type,
        };
    }

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
        return $query->whereIn('type', [
            'deposit',
            'commission',
            'debt',
            'settlement',
            'refund',
            'admin_fee',
            'withdrawal',
            'adjustment',
        ]);
    }

    public function scopeForPublicType(Builder $query, string $type): Builder
    {
        return match ($type) {
            'commission' => $query->where(function (Builder $query): void {
                $query->whereIn('type', ['commission', 'admin_fee'])
                    ->orWhere(function (Builder $query): void {
                        $query->where('type', 'debt')
                            ->where('reference', 'like', self::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'%');
                    });
            }),
            'debt' => $query->where('type', 'debt')
                ->where(function (Builder $query): void {
                    $query->whereNull('reference')
                        ->orWhere('reference', 'not like', self::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'%');
                }),
            'deposit' => $query->where(function (Builder $query): void {
                $query->where('type', 'deposit')
                    ->orWhere(function (Builder $query): void {
                        $query->where('type', 'adjustment')->where('amount', '>=', 0);
                    });
            }),
            'refund' => $query->where(function (Builder $query): void {
                $query->whereIn('type', ['refund', 'withdrawal'])
                    ->orWhere(function (Builder $query): void {
                        $query->where('type', 'adjustment')->where('amount', '<', 0);
                    });
            }),
            'settlement' => $query->where('type', 'settlement'),
            default => $query->whereRaw('1 = 0'),
        };
    }

    public function publicType(): string
    {
        if (
            in_array((string) $this->type, ['commission', 'admin_fee'], true)
            || ((string) $this->type === 'debt' && str_starts_with((string) $this->reference, self::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX))
        ) {
            return 'commission';
        }

        return self::normalizePublicType((string) $this->type, (float) $this->amount);
    }

    public function publicAmount(): float
    {
        return abs((float) $this->amount);
    }

    protected function casts(): array
    {
        return [
            'type' => 'string',
            'amount' => 'decimal:2',
            'debt_settled_amount' => 'decimal:2',
            'balance_before' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'debt_balance_before' => 'decimal:2',
            'debt_balance_after' => 'decimal:2',
        ];
    }
}
