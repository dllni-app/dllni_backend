<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CancellationPolicy;
use App\Models\User;
use App\Models\Worker;
use Carbon\CarbonInterface;
use Database\Seeders\Support\SeederMedia;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\OrderType;
use Modules\Resturants\Enums\RestaurantPickupMode;
use Modules\Resturants\Models\Favorite;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\OrderStatusLog;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\Review;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Enums\SmPickupMode;
use Modules\Supermarket\Models\SmCart;
use Modules\Supermarket\Models\SmCartItem;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderItem;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmStore;
use Modules\User\Models\UserAddress;

final class UserAppScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->where('email', 'user@dllni.sy')->first();
        if (! $user) {
            return;
        }

        $this->seedUserProfileScenario($user);
        $this->seedRestaurantScenario($user);
        $this->seedSupermarketScenario($user);
        $this->seedSupermarketCartScenario($user);
        $this->seedCleaningScenario($user);
    }

    private function seedUserProfileScenario(User $user): void
    {
        SeederMedia::ensureSingleMedia(
            $user,
            'primary-image',
            'https://images.unsplash.com/photo-1438761681033-6461ffad8d80?auto=format&fit=crop&w=600&q=80',
            "user-{$user->id}-primary"
        );

        SeederMedia::ensureSingleMedia(
            $user,
            'images',
            'https://images.unsplash.com/photo-1487412720507-e7ab37603c6f?auto=format&fit=crop&w=600&q=80',
            "user-{$user->id}-gallery-1"
        );

        $this->seedAddresses($user);
        $this->seedNotifications($user);
    }

    private function seedAddresses(User $user): void
    {
        UserAddress::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'label' => 'ا�"�.�?ز�"',
            ],
            [
                'mobile' => '+963944000222',
                'city' => 'ح�"ب',
                'neighborhood' => 'ا�"ح�.دا�?�Sة',
                'street' => 'شارع ا�"�,دس',
                'building' => '12',
                'floor' => '3',
                'directions' => 'بجا�?ب ا�"ص�Sد�"�Sة�O ا�"�.دخ�" ا�"خ�"ف�S.',
                'latitude' => 36.1795,
                'longitude' => 37.1082,
                'is_default' => true,
            ]
        );

        UserAddress::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'label' => 'ا�"ع�.�"',
            ],
            [
                'mobile' => '+963944000223',
                'city' => 'ح�"ب',
                'neighborhood' => 'ا�"فر�,ا�?',
                'street' => 'شارع عبد ا�"�,ادر ا�"صا�"ح',
                'building' => '7',
                'floor' => '1',
                'directions' => '�.�,اب�" ا�"ب�?�f�O ا�"د�^ر ا�"أ�^�".',
                'latitude' => 36.2021,
                'longitude' => 37.1343,
                'is_default' => false,
            ]
        );
    }

    private function seedNotifications(User $user): void
    {
        $databaseType = 'Illuminate\\Notifications\\DatabaseNotification';

        $user->notifications()
            ->where('data->seedTag', 'user-app-scenario')
            ->delete();

        $notifications = [
            [
                'type' => 'order',
                'title' => 'Order accepted',
                'body' => 'Restaurant accepted your order and started preparing it.',
                'bookingId' => 101,
                'read_at' => null,
            ],
            [
                'type' => 'order',
                'title' => 'Order ready for pickup',
                'body' => 'Your restaurant order is now ready for pickup.',
                'bookingId' => 101,
                'read_at' => null,
            ],
            [
                'type' => 'promo',
                'title' => 'New offer available',
                'body' => 'Use code WELCOME15 on your next checkout.',
                'read_at' => null,
            ],
            [
                'type' => 'promo',
                'title' => 'Flash deal nearby',
                'body' => 'A featured supermarket near you has a limited-time discount.',
                'read_at' => now()->subMinutes(5),
            ],
            [
                'type' => 'delivery',
                'title' => 'Order on the way',
                'body' => 'Your driver is approaching your saved address.',
                'timeWarningId' => 11,
                'read_at' => now()->subMinutes(20),
            ],
            [
                'type' => 'account',
                'title' => 'Security reminder',
                'body' => 'Please review your account security settings.',
                'read_at' => now()->subHours(2),
            ],
        ];

        foreach ($notifications as $payload) {
            $readAt = $payload['read_at'];
            unset($payload['read_at']);

            $user->notifications()->create([
                'id' => (string) Str::uuid(),
                'type' => $databaseType,
                'data' => [...$payload, 'seedTag' => 'user-app-scenario'],
                'read_at' => $readAt,
            ]);
        }
    }

    private function seedRestaurantScenario(User $user): void
    {
        $restaurants = Restaurant::query()
            ->where('is_active', true)
            ->orderBy('id')
            ->limit(4)
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
            ->limit(12)
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

        $timelineAnchor = now()->subDays(1)->setTime(16, 0, 0);

        $order = Order::query()->updateOrCreate([
            'order_number' => sprintf('USR-REST-%d-001', $restaurant->id),
        ], [
            'user_id' => $user->id,
            'restaurant_id' => $restaurant->id,
            'cancellation_policy_id' => CancellationPolicy::query()
                ->where('module', 'restaurant')
                ->where('is_default', true)
                ->value('id'),
            'status' => OrderStatus::Completed->value,
            'order_type' => OrderType::Pickup->value,
            'pickup_mode' => RestaurantPickupMode::ImmediatePickup->value,
            'subtotal' => 0,
            'discount_amount' => 0,
            'tax_amount' => 0,
            'service_fee' => 0,
            'total_amount' => 0,
            'accepted_at' => $timelineAnchor->copy()->addMinutes(3),
            'preparing_at' => $timelineAnchor->copy()->addMinutes(8),
            'ready_for_pickup_at' => $timelineAnchor->copy()->addMinutes(22),
            'picked_up_at' => $timelineAnchor->copy()->addMinutes(28),
            'customer_pickup_confirmed_at' => $timelineAnchor->copy()->addMinutes(29),
            'completed_at' => $timelineAnchor->copy()->addMinutes(30),
        ]);

        if (! $order->relationLoaded('orderItems') || $order->orderItems()->count() === 0) {
            $items = $products->take(3);
            $subtotal = 0.0;

            $order->orderItems()->delete();

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

        $this->recreateRestaurantOrderStatusTimeline($order->fresh(), $timelineAnchor);
    }

    private function recreateRestaurantOrderStatusTimeline(Order $order, CarbonInterface $anchor): void
    {
        if ($order->status !== OrderStatus::Completed) {
            return;
        }

        OrderStatusLog::query()->where('order_id', $order->id)->delete();

        $steps = [
            [null, OrderStatus::Pending->value, 'Order placed by customer.', 0],
            [OrderStatus::Pending->value, OrderStatus::Accepted->value, 'Restaurant accepted your order.', 3],
            [OrderStatus::Accepted->value, OrderStatus::Preparing->value, 'Kitchen started preparing your items.', 8],
            [OrderStatus::Preparing->value, OrderStatus::ReadyForPickup->value, 'Your order is ready for pickup.', 22],
            [OrderStatus::ReadyForPickup->value, OrderStatus::PickedUp->value, 'Order handed to customer.', 28],
            [OrderStatus::PickedUp->value, OrderStatus::Completed->value, 'Order completed. Thank you!', 30],
        ];

        foreach ($steps as [$fromStatus, $toStatus, $note, $offsetMinutes]) {
            $at = $anchor->copy()->addMinutes($offsetMinutes);

            OrderStatusLog::forceCreate([
                'order_id' => $order->id,
                'from_status' => $fromStatus,
                'to_status' => $toStatus,
                'note' => $note,
                'created_at' => $at,
                'updated_at' => $at,
            ]);
        }
    }

    private function seedSupermarketScenario(User $user): void
    {
        $stores = SmStore::query()
            ->where('is_active', true)
            ->where(fn($query) => $query
                ->whereNull('suspension_until')
                ->orWhere('suspension_until', '<=', now()))
            ->orderBy('id')
            ->limit(2)
            ->get();

        if ($stores->isEmpty()) {
            return;
        }

        foreach ($stores as $store) {
            Favorite::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'favorable_type' => SmStore::class,
                    'favorable_id' => $store->id,
                ],
                []
            );
        }

        $store = $stores->first();
        if (! $store instanceof SmStore) {
            return;
        }

        $products = SmProduct::query()
            ->where('store_id', $store->id)
            ->where('is_available', true)
            ->where('stock_quantity', '>', 0)
            ->orderBy('id')
            ->limit(3)
            ->get();

        if ($products->isEmpty()) {
            return;
        }

        $order = SmOrder::query()->updateOrCreate([
            'order_number' => sprintf('USR-SM-%d-001', $store->id),
        ], [
            'customer_id' => $user->id,
            'store_id' => $store->id,
            'cancellation_policy_id' => CancellationPolicy::query()
                ->where('module', 'supermarket')
                ->where('is_default', true)
                ->value('id'),
            'status' => SmOrderStatus::Completed->value,
            'pickup_mode' => SmPickupMode::ImmediatePickup->value,
            'subtotal' => 0,
            'discount_amount' => 0,
            'service_fee' => 0,
            'total_amount' => 0,
            'ready_for_pickup_at' => now()->subDay()->addMinutes(20),
            'picked_up_at' => now()->subDay()->addMinutes(35),
            'customer_pickup_confirmed_at' => now()->subDay()->addMinutes(36),
        ]);

        $order->items()->delete();

        $subtotal = 0.0;
        foreach ($products as $index => $product) {
            $unitPrice = (float) ($product->discounted_price ?? $product->price ?? 0);
            $qty = $index === 0 ? 2 : 1;
            $total = round($unitPrice * $qty, 2);
            $subtotal += $total;

            SmOrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'total_price' => $total,
                'product_name' => $product->name,
            ]);
        }

        $order->update([
            'subtotal' => round($subtotal, 2),
            'total_amount' => round($subtotal, 2),
        ]);
    }

    private function seedSupermarketCartScenario(User $user): void
    {
        $store = SmStore::query()
            ->where('is_active', true)
            ->where(fn($query) => $query
                ->whereNull('suspension_until')
                ->orWhere('suspension_until', '<=', now()))
            ->orderBy('id')
            ->first();

        if (! $store instanceof SmStore) {
            return;
        }

        $products = SmProduct::query()
            ->where('store_id', $store->id)
            ->where('is_available', true)
            ->where('stock_quantity', '>', 0)
            ->orderBy('id')
            ->limit(3)
            ->get();

        if ($products->isEmpty()) {
            return;
        }

        $cart = SmCart::query()->updateOrCreate(
            [
                'user_id' => $user->id,
                'store_id' => $store->id,
            ],
            []
        );

        foreach ($products as $index => $product) {
            $unitPrice = (float) ($product->discounted_price ?? $product->price ?? 0);

            SmCartItem::query()->updateOrCreate(
                [
                    'cart_id' => $cart->id,
                    'product_id' => $product->id,
                ],
                [
                    'quantity' => $index + 1,
                    'unit_price' => $unitPrice,
                ]
            );
        }
    }

    private function seedCleaningScenario(User $user): void
    {
        $worker = Worker::query()->where('is_active', true)->orderBy('id')->first()
            ?? Worker::query()->orderBy('id')->first();

        $billingPolicyId = CleaningBillingPolicy::query()->where('is_default', true)->value('id');
        if ($billingPolicyId === null) {
            return;
        }

        $cancellationPolicyId = CancellationPolicy::query()
            ->where('module', 'cleaning')
            ->where('is_default', true)
            ->value('id');

        $templates = [
            ['status' => CleaningBookingStatus::Pending, 'daysOffset' => 1, 'requiresWorker' => false],
            ['status' => CleaningBookingStatus::WorkerAssigned, 'daysOffset' => 2, 'requiresWorker' => true],
            ['status' => CleaningBookingStatus::InProgress, 'daysOffset' => 0, 'requiresWorker' => true],
            ['status' => CleaningBookingStatus::Completed, 'daysOffset' => -1, 'requiresWorker' => true],
            ['status' => CleaningBookingStatus::Cancelled, 'daysOffset' => 3, 'requiresWorker' => false],
        ];

        foreach ($templates as $index => $template) {
            if (($template['requiresWorker'] ?? false) && $worker === null) {
                continue;
            }

            $status = $template['status'];
            $scheduledDate = now()->addDays((int) $template['daysOffset'])->startOfDay();
            $basePrice = 60 + ($index * 8);
            $travelFee = 8 + $index;
            $totalPrice = $basePrice + $travelFee;

            $workStartedAt = null;
            $workFinishedAt = null;
            $customerConfirmedAt = null;
            $cancelledAt = null;
            $cancellationReason = null;

            if ($status === CleaningBookingStatus::InProgress) {
                $workStartedAt = now()->subMinutes(75);
            }

            if ($status === CleaningBookingStatus::Completed) {
                $workStartedAt = now()->subDay()->setTime(10, 0);
                $workFinishedAt = now()->subDay()->setTime(13, 0);
                $customerConfirmedAt = now()->subDay()->setTime(13, 5);
            }

            if ($status === CleaningBookingStatus::Cancelled) {
                $cancelledAt = now()->subHours(6);
                $cancellationReason = 'Customer requested another schedule';
            }

            CleaningBooking::query()->updateOrCreate(
                [
                    'booking_number' => sprintf('USR-CLN-%d-%03d', $user->id, $index + 1),
                ],
                [
                    'customer_id' => $user->id,
                    'worker_id' => $worker?->id,
                    'preferred_worker_id' => $worker?->id,
                    'cancellation_policy_id' => $cancellationPolicyId,
                    'billing_policy_id' => $billingPolicyId,
                    'status' => $status->value,
                    'property_type' => 'apartment',
                    'property_details' => [
                        'location_name' => 'Home',
                        'address' => 'Al Hamdaniyah, Aleppo',
                        'rooms' => 3,
                        'bedrooms' => 2,
                        'bathrooms' => 1,
                    ],
                    'address_latitude' => 36.1795,
                    'address_longitude' => 37.1082,
                    'estimated_sqm' => 110,
                    'estimated_hours' => 3.0,
                    'scheduled_date' => $scheduledDate,
                    'scheduled_time' => '09:00',
                    'total_hours' => 3.0,
                    'base_price' => $basePrice,
                    'addons_total' => 0,
                    'travel_fee' => $travelFee,
                    'cancellation_fee' => 0,
                    'total_price' => $totalPrice,
                    'terms_accepted' => true,
                    'work_started_at' => $workStartedAt,
                    'work_finished_at' => $workFinishedAt,
                    'customer_confirmed_at' => $customerConfirmedAt,
                    'cancelled_at' => $cancelledAt,
                    'cancellation_reason' => $cancellationReason,
                ]
            );
        }
    }
}
