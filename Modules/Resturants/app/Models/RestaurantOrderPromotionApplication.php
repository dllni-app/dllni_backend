<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RestaurantOrderPromotionApplication extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'order_id', 'offer_id', 'original_offer_id', 'restaurant_id', 'mechanism',
        'rule_version', 'application_count', 'reward_quantity', 'free_quantity',
        'discount_amount', 'net_eligible_revenue', 'currency', 'rule_snapshot',
        'status', 'applied_at', 'reversed_at', 'reversal_reason',
    ];

    protected function casts(): array
    {
        return [
            'rule_snapshot' => 'array',
            'discount_amount' => 'decimal:2',
            'net_eligible_revenue' => 'decimal:2',
            'applied_at' => 'immutable_datetime',
            'reversed_at' => 'immutable_datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function offer(): BelongsTo
    {
        return $this->belongsTo(Offer::class);
    }
}
