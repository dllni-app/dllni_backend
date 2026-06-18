<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CleaningDepositSetting extends Model
{
    protected $fillable = [
        'minimum_deposit_amount',
        'default_max_negative_balance',
        'is_enabled',
        'trust_reject_after_accept_penalty',
        'trust_minimum_for_dispatch',
    ];

    protected function casts(): array
    {
        return [
            'minimum_deposit_amount' => 'decimal:2',
            'default_max_negative_balance' => 'decimal:2',
            'is_enabled' => 'boolean',
            'trust_reject_after_accept_penalty' => 'integer',
            'trust_minimum_for_dispatch' => 'integer',
        ];
    }
}
