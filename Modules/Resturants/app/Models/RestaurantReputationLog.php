<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Resturants\Traits\FilterQueries\RestaurantReputationLogFilterQuery;

final class RestaurantReputationLog extends Model
{
    use RestaurantReputationLogFilterQuery;

    protected $fillable = [
        'restaurant_id',
        'score_delta',
        'reason',
    ];

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    protected function casts(): array
    {
        return [
            'score_delta' => 'integer',
        ];
    }
}
