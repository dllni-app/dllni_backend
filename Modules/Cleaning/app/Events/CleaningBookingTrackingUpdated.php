<?php

declare(strict_types=1);

namespace Modules\Cleaning\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CleaningBookingTrackingUpdated implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $tracking
     */
    public function __construct(
        public int $cleaningBookingId,
        public array $tracking,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('cleaning-booking.' . $this->cleaningBookingId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'CleaningBookingTrackingUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'tracking' => $this->tracking,
        ];
    }
}
