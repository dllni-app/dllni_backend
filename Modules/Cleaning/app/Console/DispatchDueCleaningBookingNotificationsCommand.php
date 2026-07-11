<?php

declare(strict_types=1);

namespace Modules\Cleaning\Console;

use Illuminate\Console\Command;
use Modules\Cleaning\Jobs\DispatchDueCleaningBookingNotificationsJob;

final class DispatchDueCleaningBookingNotificationsCommand extends Command
{
    protected $signature = 'cleaning:dispatch-due-action-notifications';

    protected $description = 'Dispatch due cleaning booking reminders and missing-action warnings';

    public function handle(): int
    {
        DispatchDueCleaningBookingNotificationsJob::dispatch();
        $this->info('Cleaning action notification job dispatched.');

        return self::SUCCESS;
    }
}
