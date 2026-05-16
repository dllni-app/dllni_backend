<?php

declare(strict_types=1);

namespace Modules\Cleaning\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ArrivalVerified implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $cleaningBookingId,
        public ?int $workerId,
        public string $arrivedAt,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel('cleaning-booking.' . $this->cleaningBookingId),
        ];

        if ($this->workerId !== null) {
            $channels[] = new PrivateChannel('cleaning-worker.' . $this->workerId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'ArrivalVerified';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'cleaningBookingId' => $this->cleaningBookingId,
            'workerId' => $this->workerId,
            'arrivedAt' => $this->arrivedAt,
            'version' => 1,
        ];
    }
}
