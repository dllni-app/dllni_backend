<?php

declare(strict_types=1);

namespace Modules\Cleaning\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Cleaning\Services\CleaningBookingActionNotificationService;

final class DispatchDueCleaningBookingNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 3;

    /** @var array<int, int> */
    public array $backoff = [60, 300, 900];

    public function handle(CleaningBookingActionNotificationService $service): void
    {
        $service->dispatchDue();
    }
}
