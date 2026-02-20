<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Resturants\Enums\PenaltyType;
use Modules\Resturants\Traits\FilterQueries\RestaurantPenaltyFilterQuery;

final class RestaurantPenalty extends Model
{
    use RestaurantPenaltyFilterQuery;

    protected $fillable = [
        'restaurant_id',
        'penalty_type',
        'amount',
        'reason',
        'resolved_at',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    protected function casts(): array
    {
        return [
            'penalty_type' => PenaltyType::class,
            'amount' => 'decimal:2',
            'resolved_at' => 'datetime',
        ];
    }
}
