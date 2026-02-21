<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Supermarket\Enums\SmRecurringOrderStatus;
use Modules\Supermarket\Traits\FilterQueries\SmRecurringOrderFilterQuery;

final class SmRecurringOrder extends Model
{
    use SmRecurringOrderFilterQuery;

    protected $table = 'sm_recurring_orders';

    protected $fillable = [
        'user_id',
        'store_id',
        'status',
        'frequency',
        'frequency_config',
        'next_run_at',
        'last_run_at',
        'paused_at',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function store(): BelongsTo
    {
        return $this->belongsTo(SmStore::class, 'store_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(SmRecurringOrderItem::class, 'recurring_order_id');
    }

    protected function casts(): array
    {
        return [
            'status' => SmRecurringOrderStatus::class,
            'frequency_config' => 'array',
            'next_run_at' => 'datetime',
            'last_run_at' => 'datetime',
            'paused_at' => 'datetime',
        ];
    }
}
