<?php

declare(strict_types=1);

use App\Services\RestaurantSystemAlertGenerator;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('restaurant:generate-system-alerts', function (RestaurantSystemAlertGenerator $generator): int {
    $count = $generator->handle();

    $this->info("Restaurant alerts generated: {$count}");

    return 0;
})->purpose('Generate proactive system alerts for restaurant operations');

Schedule::command('restaurant:generate-system-alerts')->everyFiveMinutes();
