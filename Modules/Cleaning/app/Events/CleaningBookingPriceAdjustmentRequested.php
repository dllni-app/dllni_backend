<?php

declare(strict_types=1);

namespace Modules\Cleaning\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CleaningBookingPriceAdjustmentRequested implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $cleaningBookingId,
        public int $requestId,
        public int $workerId,
        public float $oldTotalPrice,
        public float $proposedTotalPrice,
        public string $status,
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
        return 'cleaning.booking.price_adjustment_requested';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'bookingId' => $this->cleaningBookingId,
            'cleaningBookingId' => $this->cleaningBookingId,
            'requestId' => $this->requestId,
            'workerId' => $this->workerId,
            'oldTotalPrice' => $this->oldTotalPrice,
            'proposedTotalPrice' => $this->proposedTotalPrice,
            'status' => $this->status,
        ];
    }
}
