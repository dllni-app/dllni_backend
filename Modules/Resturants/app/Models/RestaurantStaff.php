<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Resturants\Traits\FilterQueries\RestaurantStaffFilterQuery;

final class RestaurantStaff extends Model
{
    use RestaurantStaffFilterQuery;

    protected $table = 'restaurant_staff';

    protected $fillable = [
        'restaurant_id',
        'user_id',
        'restaurant_role_id',
        'is_active',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(RestaurantRole::class, 'restaurant_role_id');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }
}
