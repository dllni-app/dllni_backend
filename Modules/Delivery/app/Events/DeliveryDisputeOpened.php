<?php

declare(strict_types=1);

namespace Modules\Delivery\Events;

use Illuminate\Foundation\Events\Dispatchable;

final class DeliveryDisputeOpened
{
    use Dispatchable;

    public function __construct(
        public readonly int $disputeId,
        public readonly int $orderId,
    ) {}
}
