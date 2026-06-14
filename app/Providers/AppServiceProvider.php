<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Dispute;
use App\Models\MasterProduct;
use App\Models\User;
use App\Models\Worker;
use App\Notifications\Channels\CachedFcmChannel;
use App\Services\Notifications\CachedFirebaseMessagingClient;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Notifications\ChannelManager;
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Delivery\Policies\DeliveryDriverPolicy;
use Modules\Delivery\Policies\DeliveryOrderPolicy;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantGroupOrder;
use Modules\Resturants\Models\RestaurantGroupOrderParticipant;
use Modules\Resturants\Models\RestaurantOrderDispute;
use Modules\Resturants\Policies\OrderPolicy;
use Modules\Resturants\Policies\RestaurantOrderDisputePolicy;
use Modules\Resturants\Policies\RestaurantPolicy;
use Modules\Supermarket\Models\SmCoupon;
use Modules\Supermarket\Models\SmOffer;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderDispute;
use Modules\Supermarket\Models\SmOrderDisputeMessage;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;
use Modules\Supermarket\Models\SmStoreDailyStat;
use Modules\Supermarket\Models\SmStoreDocument;
use Modules\Supermarket\Models\SmStoreTrustLog;
use Modules\Supermarket\Policies\SmCouponPolicy;
use Modules\Supermarket\Policies\SmOfferPolicy;
use Modules\Supermarket\Policies\SmOrderDisputeMessagePolicy;
use Modules\Supermarket\Policies\SmOrderDisputePolicy;
use Modules\Supermarket\Policies\SmOrderPolicy;
use Modules\Supermarket\Policies\SmProductPolicy;
use Modules\Supermarket\Policies\SmStoreDailyStatPolicy;
use Modules\Supermarket\Policies\SmStoreDocumentPolicy;
use Modules\Supermarket\Policies\SmStorePolicy;
use Modules\Supermarket\Policies\SmStoreTrustLogPolicy;
use Modules\User\Models\MarketingOffer;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CachedFirebaseMessagingClient::class);

        // override default language path so our root lang/ directory is used
        // (instead of resources/lang).  This must happen before the translator
        // loads any files, so register() is the right spot.
        $this->app->useLangPath(base_path('lang'));

        if ($this->app->environment('local') && class_exists(\Laravel\Telescope\TelescopeServiceProvider::class)) {

            $this->app->register(\Laravel\Telescope\TelescopeServiceProvider::class);

            $this->app->register(TelescopeServiceProvider::class);
        }
    }

    public function boot(): void
    {
        // our language files live at the repository root instead of the default
        // resources/lang directory.  Tell Laravel to use that path so keys like
        // "cleaning_admin.overview.title" resolve properly.
        $this->app->useLangPath(base_path('lang'));

        RateLimiter::for('cleaning-start-verification', function (Request $request): Limit {
            $userId = $request->user()?->id ?? 'guest';
            $orderId = (string) $request->route('order');

            return Limit::perMinute(5)->by($userId.'|'.$orderId);
        });

        $this->bootModelsDefaults();
        $this->bootMorphMap();
        $this->bootBroadcastChannels();
        $this->bootRestaurantPolicies();
        $this->bootSupermarketPolicies();
        $this->bootDeliveryPolicies();
        $this->bootCachedFcmChannel();
    }

    private function bootCachedFcmChannel(): void
    {
        Notification::resolved(function (ChannelManager $service): void {
            $service->extend('fcm', function ($app): CachedFcmChannel {
                return new CachedFcmChannel($app->make(CachedFirebaseMessagingClient::class));
            });
        });
    }

    private function bootDeliveryPolicies(): void
    {
        Gate::policy(DeliveryOrder::class, DeliveryOrderPolicy::class);
        Gate::policy(DeliveryDriver::class, DeliveryDriverPolicy::class);
    }

    private function bootRestaurantPolicies(): void
    {
        Gate::policy(Restaurant::class, RestaurantPolicy::class);
        Gate::policy(Order::class, OrderPolicy::class);
        Gate::policy(RestaurantOrderDispute::class, RestaurantOrderDisputePolicy::class);
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
        Broadcast::channel('vote.{voteId}', function (User $user, int $voteId): bool {
            return (int) $user->id !== 0;
        }, ['guards' => ['sanctum']]);

        Broadcast::channel('group-order.{groupOrderId}', function (User $user, int $groupOrderId): bool {
            $isOrganizer = RestaurantGroupOrder::query()
                ->whereKey($groupOrderId)
                ->where('user_id', $user->id)
                ->exists();

            if ($isOrganizer) {
                return true;
            }

            return RestaurantGroupOrderParticipant::query()
                ->where('group_order_id', $groupOrderId)
                ->where('user_id', $user->id)
                ->exists();
        }, ['guards' => ['sanctum']]);

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

            if ($user->worker && $booking->workerAssignments()
                ->where('worker_id', $user->worker->id)
                ->whereIn('status', CleaningBookingWorkerAssignmentStatus::acceptedValues())
                ->exists()) {
                return true;
            }

            return false;
        }, ['guards' => ['sanctum']]);

        Broadcast::channel('cleaning-worker.{workerId}', function (User $user, int $workerId): bool {
            return (int) ($user->worker?->id ?? 0) === $workerId;
        }, ['guards' => ['sanctum']]);

        Broadcast::channel('cleaning-customer.{customerId}', function (User $user, int $customerId): bool {
            return (int) $user->id === $customerId;
        }, ['guards' => ['sanctum']]);

        Broadcast::routes(['middleware' => ['auth:sanctum']]);
    }

    private function bootMorphMap(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'worker' => Worker::class,
            'dispute' => Dispute::class,
            'master_product' => MasterProduct::class,
            'category' => Category::class,
            'offer' => Offer::class,
            'product' => Product::class,
            'restaurant' => Restaurant::class,
            'sm_product' => SmProduct::class,
            'sm_offer' => SmOffer::class,
            'marketing_offer' => MarketingOffer::class,
            'cleaning_booking' => CleaningBooking::class,
            'event_booking' => \Modules\Cleaning\Models\EventBooking::class,
            'delivery_company' => DeliveryCompany::class,
            'delivery_driver' => DeliveryDriver::class,
            'delivery_order' => DeliveryOrder::class,
        ]);
    }

    private function bootModelsDefaults(): void
    {
        Model::unguard();
    }
}
