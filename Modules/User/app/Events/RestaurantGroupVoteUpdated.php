<?php

declare(strict_types=1);

namespace Modules\User\Events;

use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Resturants\Models\RestaurantGroupVote;

final class RestaurantGroupVoteUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $votePayload
     */
    public function __construct(
        public RestaurantGroupVote $vote,
        public array $votePayload,
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
        return [
            'vote' => $this->votePayload,
        ];
    }
}
