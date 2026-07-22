<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

final class CleaningDepositSetting extends Model
{
    use LogsActivity;

    protected $fillable = [
        'minimum_deposit_amount',
        'restriction_threshold_percent',
        'trust_reject_after_accept_penalty',
        'trust_minimum_for_dispatch',
        'trust_low_rating_threshold',
        'trust_low_rating_penalty',
    ];

    protected function casts(): array
    {
        return [
            'minimum_deposit_amount' => 'decimal:2',
            'restriction_threshold_percent' => 'decimal:2',
            'trust_reject_after_accept_penalty' => 'integer',
            'trust_minimum_for_dispatch' => 'integer',
            'trust_low_rating_threshold' => 'integer',
            'trust_low_rating_penalty' => 'integer',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->logFillable()
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }
}
