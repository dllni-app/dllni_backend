<?php

declare(strict_types=1);

namespace Modules\Cleaning\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class WorkerLocationUpdated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $cleaningBookingId,
        public float $latitude,
        public float $longitude,
        public int $workerId,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('cleaning-booking.'.$this->cleaningBookingId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'WorkerLocationUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'workerId' => $this->workerId,
            'updatedAt' => now()->toIso8601String(),
        ];
    }
}
