<?php

declare(strict_types=1);

namespace Modules\Cleaning\Console;

use Illuminate\Console\Command;
use Modules\Cleaning\Services\CleaningBookingActionNotificationService;

final class DispatchDueCleaningBookingNotificationsCommand extends Command
{
    protected $signature = 'cleaning:dispatch-due-action-notifications';

    protected $description = 'Dispatch due cleaning booking reminders and missing-action warnings';

    public function handle(CleaningBookingActionNotificationService $service): int
    {
        $count = $service->dispatchDue();
        $this->info("Cleaning action notifications dispatched: {$count}");

        return self::SUCCESS;
    }
}
