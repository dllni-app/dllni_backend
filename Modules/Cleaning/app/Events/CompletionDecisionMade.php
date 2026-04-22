<?php

declare(strict_types=1);

namespace Modules\Cleaning\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class CompletionDecisionMade implements ShouldBroadcast
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $cleaningBookingId,
        public ?int $workerId,
        public string $decision,
        public ?string $message,
        public string $decidedAt,
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
            'workerId' => $this->workerId,
            'decision' => $this->decision,
            'message' => $this->message,
            'decidedAt' => $this->decidedAt,
            'version' => 1,
        ];
    }
}
