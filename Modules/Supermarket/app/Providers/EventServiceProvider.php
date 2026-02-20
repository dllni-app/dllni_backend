<?php

declare(strict_types=1);

namespace Modules\Supermarket\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

final class EventServiceProvider extends ServiceProvider
{
    /** @var array<string, array<int, string>> */
    protected $listen = [];

    protected function configureEmailVerification(): void {}
}
