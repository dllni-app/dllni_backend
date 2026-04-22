<?php

declare(strict_types=1);

namespace Modules\Cleaning\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CleaningOrderAwaitingCustomerCompletion implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $cleaningBookingId,
        public ?int $workerId,
        public string $status,
        public ?string $expiresAt = null,
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
        return 'cleaning_order.awaiting_customer_completion';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'cleaningBookingId' => $this->cleaningBookingId,
            'workerId' => $this->workerId,
            'status' => $this->status,
            'expiresAt' => $this->expiresAt,
        ];
    }
}
