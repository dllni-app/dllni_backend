<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

it('returns order counts grouped by hour for the last five hours and current hour', function () {
    Carbon::setTestNow(Carbon::create(2026, 3, 7, 15, 30, 0));

    $ownerId = DB::table('users')->insertGetId([
        'name' => 'Owner User',
        'email' => 'owner@example.com',
        'password' => 'password',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $customerId = DB::table('users')->insertGetId([
        'name' => 'Customer User',
        'email' => 'customer@example.com',
        'password' => 'password',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $storeId = DB::table('sm_stores')->insertGetId([
        'owner_user_id' => $ownerId,
        'name' => 'Test Store',
        'slug' => 'test-store',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $orderNumber = 1;
    $insertOrders = static function (int $count, DateTimeInterface $createdAt) use (&$orderNumber, $customerId, $storeId): void {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'customer_id' => $customerId,
                'store_id' => $storeId,
                'order_number' => 'ORD-' . mb_str_pad((string) $orderNumber++, 6, '0', STR_PAD_LEFT),
                'status' => 'pending',
                'pickup_mode' => 'immediate_pickup',
                'subtotal' => 100,
                'discount_amount' => 0,
                'service_fee' => 0,
                'total_amount' => 100,
                'created_at' => $createdAt,
                'updated_at' => $createdAt,
            ];
        }

        DB::table('sm_orders')->insert($rows);
    };

    $insertOrders(2, now()->copy()->subHours(5)->addMinutes(5));
    $insertOrders(1, now()->copy()->subHours(4)->addMinutes(12));
    $insertOrders(3, now()->copy()->subHours(3)->addMinutes(7));
    $insertOrders(1, now()->copy()->subHours(1)->addMinutes(20));
    $insertOrders(2, now()->copy()->addMinutes(1));
    $insertOrders(4, now()->copy()->subHours(6));

    $response = getJson('/api/v1/sm-orders/hourly-count');

    $response->assertOk();
    $response->assertJson([
        'data' => [
            ['hour' => 10, 'ordersCount' => 2],
            ['hour' => 11, 'ordersCount' => 1],
            ['hour' => 12, 'ordersCount' => 3],
            ['hour' => 13, 'ordersCount' => 0],
            ['hour' => 14, 'ordersCount' => 1],
            ['hour' => 15, 'ordersCount' => 2],
        ],
    ]);

    Carbon::setTestNow();
});
