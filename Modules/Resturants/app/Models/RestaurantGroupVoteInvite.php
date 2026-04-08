<?php

declare(strict_types=1);

namespace Modules\Resturants\Models;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class RestaurantGroupVoteInvite extends Model
{
    protected $fillable = [
        'vote_id',
        'user_id',
    ];

    public function vote(): BelongsTo
    {
        return $this->belongsTo(RestaurantGroupVote::class, 'vote_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
