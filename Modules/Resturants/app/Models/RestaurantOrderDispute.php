<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Resturants\Enums\RestaurantDisputeStatus;
use Modules\Resturants\Traits\FilterQueries\RestaurantOrderDisputeFilterQuery;

final class RestaurantOrderDispute extends Model
{
    use RestaurantOrderDisputeFilterQuery;

    protected $fillable = [
        'order_id',
        'user_id',
        'ticket_number',
        'status',
        'resolution_type',
        'refund_amount',
        'deduction_amount',
        'payout_hold_status',
        'description',
        'resolved_by_user_id',
        'resolved_at',
        'admin_note',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by_user_id');
    }

    public function messages(): HasMany
    {
        return $this->hasMany(RestaurantOrderDisputeMessage::class, 'restaurant_order_dispute_id');
    }

    public function scopeRestaurantId(Builder $query, mixed $value): Builder
    {
        if (empty($value)) {
            return $query;
        }

        return $query->whereHas('order', fn (Builder $q) => $q->where('restaurant_id', $value));
    }

    protected function casts(): array
    {
        return [
            'status' => RestaurantDisputeStatus::class,
            'refund_amount' => 'decimal:2',
            'deduction_amount' => 'decimal:2',
            'resolved_at' => 'datetime',
        ];
    }
}
