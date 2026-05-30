<?php

declare(strict_types=1);

namespace Modules\Delivery\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Services\DriverTrustService;

final class RecoverDriverTrustScoreJob implements ShouldQueue
{
    use Queueable;

    public function handle(DriverTrustService $driverTrustService): void
    {
        $points = max(1, (int) config('delivery.trust.daily_recovery_points', 1));

        DeliveryDriver::query()
            ->where('is_active', true)
            ->where('is_suspended', false)
            ->where('open_disputes_count', 0)
            ->each(function (DeliveryDriver $driver) use ($driverTrustService, $points): void {
                $driverTrustService->recoverScore($driver->fresh(), $points);
            });
    }
}
