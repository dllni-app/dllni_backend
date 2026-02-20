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

        foreach ($stores as $store) {
            $products = $store->products;
            if ($products->isEmpty()) {
                continue;
            }

            for ($i = 0; $i < 3; $i++) {
                $orderNumber = 'SM-'.mb_strtoupper(Str::random(6)).'-'.$store->id.'-'.$i;
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

                $order = SmOrder::create([
                    'customer_id' => $customer->id,
                    'store_id' => $store->id,
                    'coupon_id' => null,
                    'cancellation_policy_id' => $policy?->id,
                    'order_number' => $orderNumber,
                    'status' => SmOrderStatus::Completed->value,
                    'pickup_mode' => SmPickupMode::ImmediatePickup->value,
                    'pickup_scheduled_for' => null,
                    'ready_for_pickup_at' => now()->subDays($i)->addMinutes(20),
                    'picked_up_at' => now()->subDays($i)->addMinutes(35),
                    'customer_pickup_confirmed_at' => now()->subDays($i)->addMinutes(36),
                    'subtotal' => $subtotal,
                    'discount_amount' => 0,
                    'service_fee' => $serviceFee,
                    'total_amount' => $totalAmount,
                    'special_instructions' => $i === 0 ? 'بدون أكياس بلاستيك إن أمكن' : null,
                ]);

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
