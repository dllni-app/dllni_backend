<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use App\Models\User;
use Database\Factories\RestaurantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Resturants\Enums\PriceRange;
use Modules\Resturants\Traits\FilterQueries\RestaurantFilterQuery;

final class Restaurant extends Model
{
    use HasFactory;
    use RestaurantFilterQuery;

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

    public function categories(): HasMany
    {
        return $this->hasMany(Category::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function operatingHours(): HasMany
    {
        return $this->hasMany(OperatingHour::class, 'restaurant_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(RestaurantDocument::class, 'restaurant_id');
    }

    public function cuisineTypes(): BelongsToMany
    {
        return $this->belongsToMany(CuisineType::class, 'cuisine_type_restaurant')
            ->withTimestamps();
    }

    public function reputationLogs(): HasMany
    {
        return $this->hasMany(RestaurantReputationLog::class, 'restaurant_id');
    }

    public function penalties(): HasMany
    {
        return $this->hasMany(RestaurantPenalty::class, 'restaurant_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(Offer::class);
    }

    public function promoCodes(): HasMany
    {
        return $this->hasMany(PromoCode::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(RestaurantStaff::class, 'restaurant_id');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(RestaurantRole::class, 'restaurant_id');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    protected static function newFactory(): RestaurantFactory
    {
        return RestaurantFactory::new();
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
