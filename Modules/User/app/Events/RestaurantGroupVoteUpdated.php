<?php

declare(strict_types=1);

namespace Modules\User\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Resturants\Models\RestaurantGroupVote;

final class RestaurantGroupVoteUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $publicPayload  Neutral realtime payload. Clients must refetch vote details with their own token for personalized current-user state.
     */
    public function __construct(
        public RestaurantGroupVote $vote,
        public array $publicPayload,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("vote.{$this->vote->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'vote.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return $this->publicPayload;
    }
}
