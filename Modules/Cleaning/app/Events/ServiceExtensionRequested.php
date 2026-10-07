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
        public ?float $baseAmount = null,
        public ?float $adminMargin = null,
        public ?float $totalAmount = null,
        public ?int $sessionId = null,
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        if ($this->workerId !== null) {
            return [
                new PrivateChannel('cleaning-worker.' . $this->workerId),
            ];
        }

        // Legacy fallback for warnings that are not explicitly targeted.
        return [
            new PrivateChannel('cleaning-booking.' . $this->cleaningBookingId),
        ];
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
            'sessionId' => $this->sessionId,
            'session_id' => $this->sessionId,
            'requestedMinutes' => $this->requestedMinutes,
            'baseAmount' => $this->baseAmount,
            'adminMargin' => $this->adminMargin,
            'totalAmount' => $this->totalAmount ?? $this->additionalAmount,
            'additionalAmount' => $this->additionalAmount ?? $this->totalAmount,
            'currency' => $this->currency,
            'version' => 1,
        ];
    }
}
