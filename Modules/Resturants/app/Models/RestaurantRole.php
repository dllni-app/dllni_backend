<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Resturants\Traits\FilterQueries\RestaurantRoleFilterQuery;

final class RestaurantRole extends Model
{
    use RestaurantRoleFilterQuery;

    protected $fillable = [
        'restaurant_id',
        'name',
        'slug',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(RestaurantStaff::class, 'restaurant_role_id');
    }

    public function permissions(): BelongsToMany
    {
        return $this->belongsToMany(
            \Spatie\Permission\Models\Permission::class,
            'restaurant_role_permission',
            'restaurant_role_id',
            'permission_id'
        )->withTimestamps();
    }
}
