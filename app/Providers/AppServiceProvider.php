<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\ServiceProvider;
use Modules\Cleaning\Models\CleaningBooking;

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
        $this->bootBroadcastChannels();
    }

    private function bootBroadcastChannels(): void
    {
        Broadcast::channel('cleaning-booking.{bookingId}', function (User $user, int $bookingId): bool {
            $booking = CleaningBooking::query()->find($bookingId);

            if (! $booking) {
                return false;
            }

            if ($booking->customer_id === $user->id) {
                return true;
            }

            if ($user->worker && $booking->worker_id === $user->worker->id) {
                return true;
            }

            return false;
        });

        Broadcast::routes(['middleware' => ['auth:sanctum']]);
    }

    private function bootMorphMap(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'cleaning_booking' => CleaningBooking::class,
            'event_booking' => \Modules\Cleaning\Models\EventBooking::class,
        ]);
    }

    private function bootModelsDefaults(): void
    {
        Model::unguard();
    }
}
