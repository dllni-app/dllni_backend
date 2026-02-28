<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\User;
use App\Models\Worker;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Supermarket\Models\SmCoupon;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderDispute;
use Modules\Supermarket\Models\SmOrderDisputeMessage;
use Modules\Supermarket\Models\SmOffer;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Models\SmStoreDailyStat;
use Modules\Supermarket\Models\SmStoreDocument;
use Modules\Supermarket\Models\SmStoreTrustLog;
use Modules\Supermarket\Policies\SmCouponPolicy;
use Modules\Supermarket\Policies\SmOrderDisputeMessagePolicy;
use Modules\Supermarket\Policies\SmOrderDisputePolicy;
use Modules\Supermarket\Policies\SmOrderPolicy;
use Modules\Supermarket\Policies\SmOfferPolicy;
use Modules\Supermarket\Policies\SmProductPolicy;
use Modules\Supermarket\Policies\SmStoreDailyStatPolicy;
use Modules\Supermarket\Policies\SmStoreDocumentPolicy;
use Modules\Supermarket\Policies\SmStorePolicy;
use Modules\Supermarket\Policies\SmStoreTrustLogPolicy;

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
        $this->bootSupermarketPolicies();
    }

    private function bootSupermarketPolicies(): void
    {
        Gate::policy(SmStore::class, SmStorePolicy::class);
        Gate::policy(SmStoreDocument::class, SmStoreDocumentPolicy::class);
        Gate::policy(SmStoreTrustLog::class, SmStoreTrustLogPolicy::class);
        Gate::policy(SmProduct::class, SmProductPolicy::class);
        Gate::policy(SmOffer::class, SmOfferPolicy::class);
        Gate::policy(SmCoupon::class, SmCouponPolicy::class);
        Gate::policy(SmOrder::class, SmOrderPolicy::class);
        Gate::policy(SmOrderDispute::class, SmOrderDisputePolicy::class);
        Gate::policy(SmOrderDisputeMessage::class, SmOrderDisputeMessagePolicy::class);
        Gate::policy(SmStoreDailyStat::class, SmStoreDailyStatPolicy::class);
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
            'worker' => Worker::class,
            'cleaning_booking' => CleaningBooking::class,
            'event_booking' => \Modules\Cleaning\Models\EventBooking::class,
        ]);
    }

    private function bootModelsDefaults(): void
    {
        Model::unguard();
    }
}
