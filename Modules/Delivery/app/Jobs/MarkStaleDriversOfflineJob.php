<?php

declare(strict_types=1);

namespace Modules\Delivery\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Modules\Delivery\Enums\DeliveryDriverAvailabilityStatus;
use Modules\Delivery\Models\DeliveryDriver;

final class MarkStaleDriversOfflineJob implements ShouldQueue
{
    use Queueable;

    public function handle(): void
    {
        $staleMinutes = max(1, (int) config('delivery.dispatch.stale_location_minutes', 5));

        DeliveryDriver::query()
            ->where('availability_status', DeliveryDriverAvailabilityStatus::Available->value)
            ->where(function ($query) use ($staleMinutes): void {
                $query->whereNull('last_seen_at')
                    ->orWhere('last_seen_at', '<', now()->subMinutes($staleMinutes));
            })
            ->update([
                'availability_status' => DeliveryDriverAvailabilityStatus::Offline->value,
                'updated_at' => now(),
            ]);
    }
}
