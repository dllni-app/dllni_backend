<?php

declare(strict_types=1);

namespace Modules\Supermarket\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Supermarket\Models\SmOrder;

final class ReturnProcessed
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public SmOrder $order,
        public array $returnedItems,
        public string $reason,
    ) {}
}
