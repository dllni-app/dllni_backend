<?php

declare(strict_types=1);

namespace Modules\Cleaning\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CleaningBookingCreated implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param  array<string, mixed>  $booking
     */
    public function __construct(
        public int $cleaningBookingId,
        public int $workerId,
        public array $booking = [],
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('cleaning-worker.'.$this->workerId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'CleaningBookingCreated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'version' => 1,
            'bookingId' => $this->cleaningBookingId,
            'cleaningBookingId' => $this->cleaningBookingId,
            'workerId' => $this->workerId,
            'status' => $this->booking['status'] ?? 'pending',
            'booking' => $this->booking,
        ];
    }
}
