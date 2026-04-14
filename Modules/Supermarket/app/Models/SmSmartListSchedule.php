<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class SmSmartListSchedule extends Model
{
    protected $table = 'sm_smart_list_schedules';

    protected $fillable = [
        'smart_list_id',
        'frequency_type',
        'day_of_week',
        'day_of_month',
        'run_date',
        'is_active',
        'next_run_at',
        'last_run_at',
    ];

    public function smartList(): BelongsTo
    {
        return $this->belongsTo(SmSmartList::class, 'smart_list_id');
    }

    protected function casts(): array
    {
        return [
            'run_date' => 'date',
            'is_active' => 'boolean',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
        ];
    }
}
