<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\Resturants\Enums\RestaurantGroupVoteStatus;

final class RestaurantGroupVote extends Model
{
    protected $fillable = [
        'user_id',
        'cuisine_type_id',
        'food_category_hint',
        'duration_minutes',
        'ends_at',
        'status',
        'winning_option_id',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function cuisineType(): BelongsTo
    {
        return $this->belongsTo(CuisineType::class);
    }

    public function winningOption(): BelongsTo
    {
        return $this->belongsTo(RestaurantGroupVoteOption::class, 'winning_option_id');
    }

    public function options(): HasMany
    {
        return $this->hasMany(RestaurantGroupVoteOption::class, 'vote_id')->orderBy('sort_order');
    }

    public function ballots(): HasMany
    {
        return $this->hasMany(RestaurantGroupVoteBallot::class, 'vote_id');
    }

    public function invites(): HasMany
    {
        return $this->hasMany(RestaurantGroupVoteInvite::class, 'vote_id');
    }

    protected function casts(): array
    {
        return [
            'ends_at' => 'datetime',
            'status' => RestaurantGroupVoteStatus::class,
            'duration_minutes' => 'integer',
        ];
    }
}
