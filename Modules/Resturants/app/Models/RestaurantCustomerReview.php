<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RestaurantCustomerReview extends Model
{
    protected $fillable = [
        'restaurant_id',
        'order_id',
        'customer_id',
        'created_by_user_id',
        'rating',
        'comment',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function casts(): array
    {
        return [
            'rating' => 'integer',
        ];
    }
}
