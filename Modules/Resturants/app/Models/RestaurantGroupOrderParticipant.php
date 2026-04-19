<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Resturants\Enums\RestaurantGroupOrderParticipantStatus;
use Modules\Resturants\Models\RestaurantGroupOrder;
use Modules\Resturants\Models\RestaurantGroupOrderItem;

final class RestaurantGroupOrderParticipant extends Model
{
    protected $fillable = [
        'group_order_id',
        'user_id',
        'status',
        'submitted_at',
    ];

    public function groupOrder(): BelongsTo
    {
        return $this->belongsTo(RestaurantGroupOrder::class, 'group_order_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RestaurantGroupOrderItem::class, 'participant_id');
    }

    protected function casts(): array
    {
        return [
            'status' => RestaurantGroupOrderParticipantStatus::class,
            'submitted_at' => 'datetime',
        ];
    }
}
