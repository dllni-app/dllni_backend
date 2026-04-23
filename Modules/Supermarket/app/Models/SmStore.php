<?php

declare(strict_types=1);

namespace Modules\Supermarket\Models;

use App\Models\User;
use Database\Factories\SmStoreFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Modules\Resturants\Models\Favorite;
use Modules\Supermarket\Traits\FilterQueries\SmStoreFilterQuery;

final class SmStore extends Model
{
    use HasFactory;
    use SmStoreFilterQuery;

    protected $table = 'sm_stores';

    protected $fillable = [
        'owner_user_id',
        'name',
        'slug',
        'description',
        'address',
        'city',
        'neighborhood',
        'latitude',
        'longitude',
        'phone',
        'email',
        'cover',
        'logo',
        'average_rating',
        'total_reviews',
        'trust_score',
        'warning_count',
        'is_active',
        'is_featured',
        'is_temporarily_closed',
        'suspension_until',
    ];

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function storeHours(): HasMany
    {
        return $this->hasMany(SmStoreHours::class, 'store_id');
    }

    public function categories(): HasMany
    {
        return $this->hasMany(SmCategory::class, 'store_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(SmProduct::class, 'store_id');
    }

    public function offers(): HasMany
    {
        return $this->hasMany(SmOffer::class, 'store_id');
    }

    public function highestDiscountOffer(): HasOne
    {
        return $this->hasOne(SmOffer::class, 'store_id')
            ->ofMany(['discount_value' => 'max', 'id' => 'max'], static function ($query): void {
                $query->where('is_active', true)
                    ->where(static function ($q): void {
                        $q->whereNull('ends_at')
                            ->orWhere('ends_at', '>=', now());
                    });
            });
    }

    public function coupons(): HasMany
    {
        return $this->hasMany(SmCoupon::class, 'store_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(SmOrder::class, 'store_id');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(SmStoreDocument::class, 'store_id');
    }

    public function trustLogs(): HasMany
    {
        return $this->hasMany(SmStoreTrustLog::class, 'store_id');
    }

    public function dailyStats(): HasMany
    {
        return $this->hasMany(SmStoreDailyStat::class, 'store_id');
    }

    public function commissionRules(): HasMany
    {
        return $this->hasMany(SmCommissionRule::class, 'store_id');
    }

    public function assistantQueries(): HasMany
    {
        return $this->hasMany(SmAssistantQuery::class, 'store_id');
    }

    public function recurringOrders(): HasMany
    {
        return $this->hasMany(SmRecurringOrder::class, 'store_id');
    }

    public function staff(): HasMany
    {
        return $this->hasMany(SmStoreStaff::class, 'store_id');
    }

    public function userFavorites(): MorphMany
    {
        return $this->morphMany(Favorite::class, 'favorable');
    }

    protected static function newFactory(): SmStoreFactory
    {
        return SmStoreFactory::new();
    }

    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:8',
            'longitude' => 'decimal:8',
            'average_rating' => 'decimal:2',
            'is_active' => 'boolean',
            'is_featured' => 'boolean',
            'is_temporarily_closed' => 'boolean',
            'suspension_until' => 'datetime',
        ];
    }
}
