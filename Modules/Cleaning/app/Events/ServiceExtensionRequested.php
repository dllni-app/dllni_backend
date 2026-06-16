<?php

declare(strict_types=1);

namespace Modules\Cleaning\Events;

use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

final class ServiceExtensionRequested implements ShouldBroadcastNow
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public int $warningId,
        public int $cleaningBookingId,
        public ?int $workerId,
        public ?int $requestedMinutes,
        public ?float $additionalAmount,
        public ?string $currency,
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
        return 'ServiceExtensionRequested';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'warningId' => $this->warningId,
            'cleaningBookingId' => $this->cleaningBookingId,
            'workerId' => $this->workerId,
            'requestedMinutes' => $this->requestedMinutes,
            'additionalAmount' => $this->additionalAmount,
            'currency' => $this->currency,
            'version' => 1,
        ];
    }
}
