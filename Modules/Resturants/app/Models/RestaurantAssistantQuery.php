<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use App\Models\Recipe;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Resturants\Enums\RestaurantAssistantInputMode;
use Modules\Resturants\Traits\FilterQueries\RestaurantAssistantQueryFilterQuery;

final class RestaurantAssistantQuery extends Model
{
    use RestaurantAssistantQueryFilterQuery;

    protected $fillable = [
        'user_id',
        'restaurant_id',
        'input_mode',
        'query_text',
        'matched_recipe_id',
        'context',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function restaurant(): BelongsTo
    {
        return $this->belongsTo(Restaurant::class);
    }

    public function matchedRecipe(): BelongsTo
    {
        return $this->belongsTo(Recipe::class, 'matched_recipe_id');
    }

    protected function casts(): array
    {
        return [
            'input_mode' => RestaurantAssistantInputMode::class,
            'context' => 'array',
        ];
    }
}
