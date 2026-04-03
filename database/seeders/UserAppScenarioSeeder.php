<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CancellationPolicy;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\OrderType;
use Modules\Resturants\Enums\RestaurantPickupMode;
use Modules\Resturants\Models\Favorite;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\Review;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Enums\SmPickupMode;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderItem;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;

final class UserAppScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('email', 'user@example.com')->first();
        if (! $user) {
            return;
        }

        $this->seedRestaurantScenario($user);
        $this->seedSupermarketScenario($user);
    }

    private function seedRestaurantScenario(User $user): void
    {
        $restaurants = Restaurant::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($restaurants->isEmpty()) {
            return;
        }

        foreach ($restaurants as $restaurant) {
            Favorite::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'favorable_type' => Restaurant::class,
                    'favorable_id' => $restaurant->id,
                ],
                []
            );
        }

        $products = Product::query()
            ->whereIn('restaurant_id', $restaurants->pluck('id'))
            ->where('is_available', true)
            ->orderBy('id')
            ->limit(4)
            ->get();

        foreach ($products as $product) {
            Favorite::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'favorable_type' => Product::class,
                    'favorable_id' => $product->id,
                ],
                []
            );
        }

        $restaurant = $restaurants->first();
        if (! $restaurant instanceof Restaurant) {
            return;
        }

        $orderNumber = 'USR-REST-'.$restaurant->id.'-'.Str::upper(Str::random(5));
        $existing = Order::query()
            ->where('user_id', $user->id)
            ->where('restaurant_id', $restaurant->id)
            ->first();

        $order = $existing ?? Order::create([
            'user_id' => $user->id,
            'restaurant_id' => $restaurant->id,
            'cancellation_policy_id' => CancellationPolicy::query()
                ->where('module', 'restaurant')
                ->where('is_default', true)
                ->value('id'),
            'order_number' => $orderNumber,
            'status' => OrderStatus::Completed->value,
            'order_type' => OrderType::Pickup->value,
            'pickup_mode' => RestaurantPickupMode::ImmediatePickup->value,
            'subtotal' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'service_fee' => 0,
            'total_amount' => 0,
            'accepted_at' => now()->subDays(1),
            'completed_at' => now()->subDays(1)->addMinutes(20),
        ]);

        if (! $order->relationLoaded('orderItems') && $order->orderItems()->count() === 0) {
            $items = $products->take(2);
            $subtotal = 0.0;

            foreach ($items as $product) {
                $unitPrice = (float) ($product->discounted_price ?? $product->price ?? 0);
                $qty = 1;
                $total = $unitPrice * $qty;
                $subtotal += $total;

                OrderItem::create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => $qty,
                    'unit_price' => $unitPrice,
                    'total_price' => $total,
                    'special_instructions' => null,
                ]);
            }

            $tax = round($subtotal * 0.1, 2);
            $order->update([
                'subtotal' => $subtotal,
                'tax_amount' => $tax,
                'total_amount' => $subtotal + $tax,
            ]);
        }

        Review::updateOrCreate(
            [
                'user_id' => $user->id,
                'order_id' => $order->id,
            ],
            [
                'restaurant_id' => $restaurant->id,
                'rating' => 5,
                'comment' => 'Excellent service and tasty food.',
                'updated_at' => now(),
                'created_at' => now()->subDays(1),
            ]
        );
    }

    private function seedSupermarketScenario(User $user): void
    {
        $store = SmStore::query()->where('is_active', true)->orderBy('id')->first();
        if (! $store) {
            return;
        }

        Favorite::updateOrCreate(
            [
                'user_id' => $user->id,
                'favorable_type' => SmStore::class,
                'favorable_id' => $store->id,
            ],
            []
        );

        $product = SmProduct::query()
            ->where('store_id', $store->id)
            ->where('is_available', true)
            ->orderBy('id')
            ->first();

        if (! $product) {
            return;
        }

        $orderNumber = 'USR-SM-'.$store->id.'-'.Str::upper(Str::random(5));
        $existing = SmOrder::query()
            ->where('customer_id', $user->id)
            ->where('store_id', $store->id)
            ->first();

        $order = $existing ?? SmOrder::create([
            'customer_id' => $user->id,
            'store_id' => $store->id,
            'cancellation_policy_id' => CancellationPolicy::query()
                ->where('module', 'supermarket')
                ->where('is_default', true)
                ->value('id'),
            'order_number' => $orderNumber,
            'status' => SmOrderStatus::Completed->value,
            'pickup_mode' => SmPickupMode::ImmediatePickup->value,
            'subtotal' => 0,
            'discount_amount' => 0,
            'service_fee' => 0,
            'total_amount' => 0,
        ]);

        if ($order->items()->count() === 0) {
            $unitPrice = (float) ($product->discounted_price ?? $product->price ?? 0);
            $qty = 2;
            $total = $unitPrice * $qty;

            SmOrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'total_price' => $total,
                'product_name' => $product->name,
            ]);

            $order->update([
                'subtotal' => $total,
                'total_amount' => $total,
            ]);
        }
    }
}
