<?php

declare(strict_types=1);

namespace Modules\User\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Resturants\Models\RestaurantGroupOrder;

final class RestaurantGroupOrderUpdated implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;

    /**
     * @param  array<string, mixed>  $publicPayload  Full payload from {@see \Modules\User\Services\RestaurantGroupOrderService::publicPayload} (groupOrder, participants, counts, amounts).
     */
    public function __construct(
        public RestaurantGroupOrder $groupOrder,
        public array $publicPayload,
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
        return $this->publicPayload;
    }
}
