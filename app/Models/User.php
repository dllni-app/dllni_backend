<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserModuleType;
use App\Traits\FilterQueries\UserFilterQuery;
use App\Traits\HasFcm;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Filament\Panel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantStaff;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property-read int $id
 * @property-read string $name
 * @property-read string $email
 * @property-read string|null $phone
 * @property-read UserModuleType|null $module_type
 * @property-read CarbonInterface|null $email_verified_at
 * @property-read string $password
 * @property-read string|null $remember_token
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class User extends Authenticatable implements HasMedia
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasFcm, HasRoles, InteractsWithMedia, Notifiable, UserFilterQuery;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'module_type',
        'email_verified_at',
        'password',
        'fcm_token',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'fcm_token',
    ];

    /**
     * @return array<string, string>
     */
    public function casts(): array
    {
        return [
            'id' => 'integer',
            'name' => 'string',
            'email' => 'string',
            'phone' => 'string',
            'module_type' => UserModuleType::class,
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'remember_token' => 'string',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function worker(): HasOne
    {
        return $this->hasOne(Worker::class);
    }

    public function restaurants(): HasMany
    {
        return $this->hasMany(Restaurant::class);
    }

    public function restaurantStaff(): HasOne
    {
        return $this->hasOne(RestaurantStaff::class, 'user_id');
    }

    public function carts(): HasMany
    {
        return $this->hasMany(\Modules\Resturants\Models\Cart::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(\Modules\Resturants\Models\Order::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(\Modules\Resturants\Models\Review::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(\Modules\Resturants\Models\Favorite::class);
    }

    public function cleaningBookings(): HasMany
    {
        return $this->hasMany(\Modules\Cleaning\Models\CleaningBooking::class, 'customer_id');
    }

    public function eventBookings(): HasMany
    {
        return $this->hasMany(\Modules\Cleaning\Models\EventBooking::class, 'customer_id');
    }

    public function smStores(): HasMany
    {
        return $this->hasMany(\Modules\Supermarket\Models\SmStore::class, 'owner_user_id');
    }

    public function smStoreStaff(): HasMany
    {
        return $this->hasMany(\Modules\Supermarket\Models\SmStoreStaff::class, 'user_id');
    }

    public function smCarts(): HasMany
    {
        return $this->hasMany(\Modules\Supermarket\Models\SmCart::class);
    }

    public function smOrders(): HasMany
    {
        return $this->hasMany(\Modules\Supermarket\Models\SmOrder::class, 'customer_id');
    }

    public function smSmartLists(): HasMany
    {
        return $this->hasMany(\Modules\Supermarket\Models\SmSmartList::class);
    }

    public function smRecurringOrders(): HasMany
    {
        return $this->hasMany(\Modules\Supermarket\Models\SmRecurringOrder::class);
    }

    public function smAssistantQueries(): HasMany
    {
        return $this->hasMany(\Modules\Supermarket\Models\SmAssistantQuery::class);
    }

    public function smOrderDisputes(): HasMany
    {
        return $this->hasMany(\Modules\Supermarket\Models\SmOrderDispute::class, 'opened_by_user_id');
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return $this->roles()->exists();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('primary-image')->singleFile();
    }
}
