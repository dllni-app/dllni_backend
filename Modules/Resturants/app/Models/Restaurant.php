<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Resturants\Enums\PriceRange;

final class Restaurant extends Model
{
    protected $fillable = [
        'user_id',
        'name',
        'slug',
        'description',
        'address',
        'latitude',
        'longitude',
        'phone',
        'email',
        'average_rating',
        'total_reviews',
        'estimated_preparation_time',
        'minimum_order_amount',
        'price_range',
        'reputation_score',
        'warning_count',
        'visibility_score',
        'manual_visibility_override',
        'is_active',
        'is_featured',
        'suspension_until',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'average_rating' => 'decimal:2',
            'minimum_order_amount' => 'decimal:2',
            'price_range' => PriceRange::class,
            'manual_visibility_override' => 'boolean',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'suspension_until' => 'datetime',
        ];
    }
}
