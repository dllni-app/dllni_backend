<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class CleaningWorkerDeposit extends Model
{
    protected $fillable = [
        'worker_id',
        'current_balance',
        'deposited_total',
        'withdrawn_total',
        'is_active',
    ];

    public function worker(): BelongsTo
    {
        return $this->belongsTo(Worker::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(CleaningDepositTransaction::class, 'worker_id', 'worker_id');
    }

    protected function casts(): array
    {
        return [
            'current_balance' => 'decimal:2',
            'deposited_total' => 'decimal:2',
            'withdrawn_total' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }
}
