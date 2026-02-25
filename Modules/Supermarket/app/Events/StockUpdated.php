<?php

declare(strict_types=1);

namespace Modules\Supermarket\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Supermarket\Models\SmProduct;

final class StockUpdated
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public SmProduct $product,
        public int $previousStock,
        public int $newStock,
        public string $reason,
    ) {}
}
