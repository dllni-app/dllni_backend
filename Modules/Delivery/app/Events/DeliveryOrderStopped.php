<?php

declare(strict_types=1);

namespace Modules\Delivery\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class DeliveryOrderStopped
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly string $reason,
    ) {}
}
