<?php

declare(strict_types=1);

namespace Modules\User\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Resturants\Models\RestaurantGroupOrder;

final class RestaurantGroupOrderUpdated implements ShouldBroadcast
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $groupOrderPayload
     */
    public function __construct(
        public RestaurantGroupOrder $groupOrder,
        public array $groupOrderPayload,
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("group-order.{$this->groupOrder->id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'group-order.updated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'groupOrder' => $this->groupOrderPayload,
        ];
    }
}
