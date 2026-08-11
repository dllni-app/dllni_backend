<?php

declare(strict_types=1);

namespace Modules\Cleaning\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CleaningBookingPriceAdjustmentResolved implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $cleaningBookingId,
        public int $requestId,
        public string $requestStatus,
        public float $totalPrice,
        public bool $canStartWork,
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
        return 'cleaning.booking.price_adjustment_resolved';
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
            'requestStatus' => $this->requestStatus,
            'totalPrice' => $this->totalPrice,
            'canStartWork' => $this->canStartWork,
        ];
    }
}
