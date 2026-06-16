<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class CleaningDepositSetting extends Model
{
    protected $fillable = [
        'minimum_deposit_amount',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'minimum_deposit_amount' => 'decimal:2',
            'is_enabled' => 'boolean',
        ];
    }
}
