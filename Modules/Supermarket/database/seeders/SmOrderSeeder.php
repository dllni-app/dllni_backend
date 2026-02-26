<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use App\Models\CancellationPolicy;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Enums\SmPickupMode;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderItem;
use Modules\Supermarket\Models\SmStore;

final class SmOrderSeeder extends Seeder
{
    public function run(): void
    {
        $customer = User::firstOrCreate(
            ['email' => 'supermarket.customer@example.com'],
            [
                'name' => 'عميل السوبرماركت',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
            ]
        );

        $policy = CancellationPolicy::where('module', 'supermarket')->where('is_default', true)->first();

        $stores = SmStore::with(['products' => fn ($q) => $q->where('is_available', true)->limit(5)])->take(2)->get();

        // Define statuses to seed with
        $statuses = [
            SmOrderStatus::Pending,
            SmOrderStatus::Accepted,
            SmOrderStatus::Preparing,
            SmOrderStatus::ReadyForPickup,
            SmOrderStatus::Completed,
            SmOrderStatus::Cancelled,
        ];

        foreach ($stores as $store) {
            $products = $store->products;
            if ($products->isEmpty()) {
                continue;
            }

            // Create 6 orders per store (one for each status)
            foreach ($statuses as $index => $status) {
                $orderNumber = 'SM-'.mb_strtoupper(Str::random(6)).'-'.$store->id.'-'.$index;
                if (SmOrder::where('order_number', $orderNumber)->exists()) {
                    continue;
                }

                $items = $products->random(min(3, $products->count()));
                $subtotal = 0.0;
                $orderItems = [];
                foreach ($items as $product) {
                    $qty = fake()->numberBetween(1, 3);
                    $unitPrice = (float) $product->discounted_price ?? (float) $product->price;
                    $totalPrice = round($unitPrice * $qty, 2);
                    $subtotal += $totalPrice;
                    $orderItems[] = [
                        'product_id' => $product->id,
                        'product_name' => $product->name,
                        'quantity' => $qty,
                        'unit_price' => $unitPrice,
                        'total_price' => $totalPrice,
                    ];
                }

                $subtotal = round($subtotal, 2);
                $serviceFee = round($subtotal * 0.02, 2);
                $totalAmount = $subtotal + $serviceFee;

                // Build order data based on status
                $orderData = [
                    'customer_id' => $customer->id,
                    'store_id' => $store->id,
                    'coupon_id' => null,
                    'cancellation_policy_id' => $policy?->id,
                    'order_number' => $orderNumber,
                    'status' => $status->value,
                    'pickup_mode' => SmPickupMode::ImmediatePickup->value,
                    'pickup_scheduled_for' => null,
                    'ready_for_pickup_at' => null,
                    'picked_up_at' => null,
                    'customer_pickup_confirmed_at' => null,
                    'subtotal' => $subtotal,
                    'discount_amount' => 0,
                    'service_fee' => $serviceFee,
                    'total_amount' => $totalAmount,
                    'special_instructions' => $index === 0 ? 'بدون أكياس بلاستيك إن أمكن' : null,
                    'cancelled_at' => null,
                    'cancellation_reason' => null,
                ];

                // Set timestamps based on status
                if ($status === SmOrderStatus::ReadyForPickup) {
                    $orderData['ready_for_pickup_at'] = now()->subHours(1);
                } elseif ($status === SmOrderStatus::Completed) {
                    $orderData['ready_for_pickup_at'] = now()->subDays(2)->addMinutes(20);
                    $orderData['picked_up_at'] = now()->subDays(2)->addMinutes(35);
                    $orderData['customer_pickup_confirmed_at'] = now()->subDays(2)->addMinutes(36);
                } elseif ($status === SmOrderStatus::Cancelled) {
                    $orderData['cancelled_at'] = now()->subHours(3);
                    $orderData['cancellation_reason'] = 'Out of stock';
                }

                $order = SmOrder::create($orderData);

                foreach ($orderItems as $item) {
                    SmOrderItem::create([
                        'order_id' => $order->id,
                        'product_id' => $item['product_id'],
                        'product_name' => $item['product_name'],
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                        'total_price' => $item['total_price'],
                    ]);
                }
            }
        }
    }
}
