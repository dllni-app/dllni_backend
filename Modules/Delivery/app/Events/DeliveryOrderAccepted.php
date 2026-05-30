<?php

declare(strict_types=1);

namespace Modules\Delivery\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class DeliveryOrderAccepted
{
    use Dispatchable;

    public function __construct(
        public readonly int $orderId,
        public readonly int $driverId,
    ) {}
}
