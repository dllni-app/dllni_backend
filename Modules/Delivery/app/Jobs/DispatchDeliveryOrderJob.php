<?php

declare(strict_types=1);

namespace Modules\Delivery\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Delivery\Services\DriverDispatchService;

final class DispatchDeliveryOrderJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly int $orderId,
    ) {
        $this->afterCommit();
    }

    public function handle(DriverDispatchService $dispatchService): void
    {
        $dispatchService->dispatchByOrderId($this->orderId);
    }
}
