<?php

declare(strict_types=1);

namespace Modules\Supermarket\Database\Seeders;

use App\Models\CancellationPolicy;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Enums\SmPickupMode;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderItem;
use Modules\Supermarket\Models\SmStore;

final class SmOrderSeeder extends Seeder
{
    public function run(): void
    {
        $customerId = DB::table('users')
            ->where('email', 'supermarket.customer@example.com')
            ->value('id');

        if ($customerId === null) {
            $customerId = DB::table('users')->insertGetId([
                'name' => 'عميل السوبرماركت',
                'email' => 'supermarket.customer@example.com',
                'password' => bcrypt('password'),
                'email_verified_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $policy = CancellationPolicy::where('module', 'supermarket')->where('is_default', true)->first();

        $stores = SmStore::query()
            ->with(['products' => fn ($q) => $q->where('is_available', true)->orderBy('id')])
            ->orderBy('id')
            ->take(3)
            ->get();

        $ordersPerStore = 15;

        foreach ($stores as $store) {
            $products = $store->products->values();
            if ($products->isEmpty()) {
                continue;
            }

            for ($i = 0; $i < $ordersPerStore; $i++) {
                $status = match (true) {
                    $i <= 5 => SmOrderStatus::Completed,
                    $i <= 7 => SmOrderStatus::ReadyForPickup,
                    $i === 8 => SmOrderStatus::Preparing,
                    $i === 9 => SmOrderStatus::Accepted,
                    $i === 10 => SmOrderStatus::Pending,
                    $i === 11 => SmOrderStatus::Cancelled,
                    default => SmOrderStatus::Completed,
                };

                if ($i >= 12) {
                    $status = SmOrderStatus::Completed;
                }

                $itemCount = min(3, $products->count());
                $subtotal = 0.0;
                $orderItems = [];

                for ($offset = 0; $offset < $itemCount; $offset++) {
                    $index = ($i + $offset) % $products->count();
                    $product = $products->get($index);
                    if ($product === null) {
                        continue;
                    }

                    $qty = 1 + (($i + $offset) % 3);
                    $unitPrice = (float) ($product->discounted_price ?? $product->price);
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

                $baseTime = now()->subDays($i + 1)->setTime(13, 0);

                $readyForPickupAt = null;
                $pickedUpAt = null;
                $customerPickupConfirmedAt = null;
                $cancelledAt = null;
                $cancellationReason = null;

                if ($status === SmOrderStatus::ReadyForPickup) {
                    $readyForPickupAt = $baseTime->copy()->addMinutes(20);
                }

                if ($status === SmOrderStatus::Completed) {
                    $readyForPickupAt = $baseTime->copy()->addMinutes(20);
                    $pickedUpAt = $baseTime->copy()->addMinutes(30);
                    $customerPickupConfirmedAt = $baseTime->copy()->addMinutes(31);
                }

                if ($status === SmOrderStatus::Cancelled) {
                    $cancelledAt = $baseTime->copy()->addMinutes(10);
                    $cancellationReason = 'Customer changed pickup time';
                }

                $order = SmOrder::updateOrCreate(
                    ['order_number' => sprintf('SM-%d-%03d', $store->id, $i + 1)],
                    [
                        'customer_id' => $customerId,
                        'store_id' => $store->id,
                        'coupon_id' => null,
                        'cancellation_policy_id' => $policy?->id,
                        'status' => $status->value,
                        'pickup_mode' => SmPickupMode::ImmediatePickup->value,
                        'pickup_scheduled_for' => null,
                        'ready_for_pickup_at' => $readyForPickupAt,
                        'picked_up_at' => $pickedUpAt,
                        'customer_pickup_confirmed_at' => $customerPickupConfirmedAt,
                        'subtotal' => $subtotal,
                        'discount_amount' => 0,
                        'service_fee' => $serviceFee,
                        'total_amount' => $totalAmount,
                        'special_instructions' => $i % 4 === 0 ? 'يرجى تجهيز الطلب بدون أكياس بلاستيك' : null,
                        'cancelled_at' => $cancelledAt,
                        'cancellation_reason' => $cancellationReason,
                    ]
                );

                SmOrderItem::query()->where('order_id', $order->id)->delete();

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
