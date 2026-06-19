<?php

declare(strict_types=1);

namespace Modules\Cleaning\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CompletionDecisionMade implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $cleaningBookingId,
        public ?int $workerId,
        public string $decision,
        public ?string $message,
        public string $decidedAt,
        public ?int $warningId = null,
        public ?string $status = null,
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
        return 'CompletionDecisionMade';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'cleaningBookingId' => $this->cleaningBookingId,
            'bookingId' => $this->cleaningBookingId,
            'booking_id' => $this->cleaningBookingId,
            'workerId' => $this->workerId,
            'decision' => $this->decision,
            'message' => $this->message,
            'decidedAt' => $this->decidedAt,
            'decided_at' => $this->decidedAt,
            'warningId' => $this->warningId,
            'warning_id' => $this->warningId,
            'status' => $this->status,
            'version' => 1,
        ];
    }
}
