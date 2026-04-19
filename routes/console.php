<?php

declare(strict_types=1);

use App\Services\RestaurantSystemAlertGenerator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;
use Modules\Supermarket\Jobs\DispatchDueSmartListSchedulesJob;
use Modules\User\Jobs\ProcessExpiredRestaurantGroupOrdersJob;

Artisan::command('restaurant:generate-system-alerts', function (RestaurantSystemAlertGenerator $generator): int {
    $count = $generator->handle();

    $this->info("Restaurant alerts generated: {$count}");

    return 0;
})->purpose('Generate proactive system alerts for restaurant operations');

Schedule::command('restaurant:generate-system-alerts')->everyFiveMinutes();

Artisan::command('supermarket:process-smart-list-schedules', function (): int {
    DispatchDueSmartListSchedulesJob::dispatch();

    $this->info('Smart list schedules processing job dispatched.');

    return 0;
})->purpose('Dispatch processing for due smart list schedules');

Schedule::command('supermarket:process-smart-list-schedules')->everyFiveMinutes();

Artisan::command('restaurants:process-group-orders', function (): int {
    ProcessExpiredRestaurantGroupOrdersJob::dispatch();

    $this->info('Expired restaurant group orders processing job dispatched.');

    return 0;
})->purpose('Dispatch processing for expired restaurant group orders');

Schedule::command('restaurants:process-group-orders')->everyMinute();
