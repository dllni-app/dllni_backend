<?php

declare(strict_types=1);

namespace Modules\Cleaning\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;
use Modules\Cleaning\Listeners\CloseFulfilledBookingNewOrderNotifications;

final class EventServiceProvider extends ServiceProvider
{
    /** @var array<string, array<int, string>> */
    protected $listen = [
        'eloquent.updated: Modules\\Cleaning\\Models\\CleaningBooking' => [
            CloseFulfilledBookingNewOrderNotifications::class,
        ],
    ];

    public function configureEmailVerification(): void {}
}
