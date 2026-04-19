<?php

declare(strict_types=1);

namespace Modules\User\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\User\Services\RestaurantGroupOrderService;

final class ProcessExpiredRestaurantGroupOrdersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle(RestaurantGroupOrderService $service): void
    {
        $service->processExpiredActiveOrders();
    }
}
