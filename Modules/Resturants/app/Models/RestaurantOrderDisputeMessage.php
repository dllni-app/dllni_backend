<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RestaurantOrderDisputeMessage extends Model
{
    protected $fillable = [
        'restaurant_order_dispute_id',
        'sender_id',
        'body',
    ];

    public function dispute(): BelongsTo
    {
        return $this->belongsTo(RestaurantOrderDispute::class, 'restaurant_order_dispute_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_id');
    }
}
