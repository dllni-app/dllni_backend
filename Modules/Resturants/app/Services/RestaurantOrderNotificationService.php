<?php

declare(strict_types=1);

namespace Modules\Resturants\Services;

use App\Models\User;
use App\Notifications\Core\NotificationPayloadBuilder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Restaurant;

final class RestaurantOrderNotificationService
{
    public function __construct(
        private readonly NotificationPayloadBuilder $payloadBuilder,
    ) {}

    public function orderCreated(Order $order): void
    {
        $order->loadMissing([
            'restaurant.user',
            'restaurant.staff.user',
            'orderItems.product.restaurant.user',
            'orderItems.product.restaurant.staff.user',
        ]);

        foreach ($this->orderRestaurants($order) as $restaurant) {
            $payload = $this->payloadBuilder->makeDatabasePayload(
                canonicalType: 'restaurant.owner.order_created',
                templateContext: ['order_number' => $order->order_number],
                extraData: [
                    'orderId' => $order->id,
                    'orderNumber' => $order->order_number,
                    'restaurantId' => $restaurant->id,
                    'deepLinkTarget' => 'restaurant_order_details',
                ],
            );

            foreach ($this->restaurantUsers($restaurant) as $user) {
                $user->notifications()->create([
                    'id' => (string) Str::uuid(),
                    'type' => 'restaurant.owner.order_created',
                    'data' => $payload,
                ]);
            }
        }
    }

    /** @return Collection<int, Restaurant> */
    private function orderRestaurants(Order $order): Collection
    {
        $restaurants = collect();

        if ($order->restaurant instanceof Restaurant) {
            $restaurants->push($order->restaurant);
        }

        foreach ($order->orderItems as $item) {
            $restaurant = $item->product?->restaurant;

            if ($restaurant instanceof Restaurant) {
                $restaurants->push($restaurant);
            }
        }

        return $restaurants->unique('id')->values();
    }

    /** @return EloquentCollection<int, User> */
    private function restaurantUsers(Restaurant $restaurant): EloquentCollection
    {
        $restaurant->loadMissing(['user', 'staff.user']);
        $users = new EloquentCollection;

        if ($restaurant->user instanceof User) {
            $users->push($restaurant->user);
        }

        foreach ($restaurant->staff as $staffMember) {
            if ($staffMember->is_active && $staffMember->user instanceof User) {
                $users->push($staffMember->user);
            }
        }

        return $users->unique('id')->values();
    }
}
