<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class RestaurantGroupVoteOption extends Model
{
    protected $fillable = [
        'vote_id',
        'label',
        'product_id',
        'sort_order',
    ];

    public function vote(): BelongsTo
    {
        return $this->belongsTo(RestaurantGroupVote::class, 'vote_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function ballots(): HasMany
    {
        return $this->hasMany(RestaurantGroupVoteBallot::class, 'option_id');
    }

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }
}
