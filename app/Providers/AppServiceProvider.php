<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {

            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);

            $this->app->register(TelescopeServiceProvider::class);

        }
    }

    public function boot(): void
    {
        $this->bootModelsDefaults();
        $this->bootMorphMap();
    }

    private function bootMorphMap(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'cleaning_booking' => \Modules\Cleaning\Models\CleaningBooking::class,
            'event_booking' => \Modules\Cleaning\Models\EventBooking::class,
        ]);
    }

    private function bootModelsDefaults(): void
    {
        Model::unguard();
    }
}
