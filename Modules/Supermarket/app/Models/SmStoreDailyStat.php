<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Supermarket\Traits\FilterQueries\SmStoreDailyStatFilterQuery;

final class SmStoreDailyStat extends Model
{
    use SmStoreDailyStatFilterQuery;

    protected $table = 'sm_store_daily_stats';

    protected $fillable = [
        'store_id',
        'date',
        'orders_count',
        'orders_revenue',
        'unique_customers',
        'new_customers',
    ];

    public function store(): BelongsTo
    {
        return $this->belongsTo(SmStore::class, 'store_id');
    }

    protected function casts(): array
    {
        return [
            'date' => 'date',
            'orders_revenue' => 'decimal:2',
        ];
    }
}
