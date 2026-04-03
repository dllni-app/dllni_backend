<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;

it('returns order counts grouped by day and status for the week starting on saturday', function () {
    // Set test date to Wednesday, March 4, 2026
    Carbon::setTestNow(Carbon::create(2026, 3, 4, 15, 30, 0));

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
    $insertOrders = static function (int $count, string $status, DateTimeInterface $createdAt) use (&$orderNumber, $customerId, $storeId): void {
        $rows = [];
        for ($i = 0; $i < $count; $i++) {
            $rows[] = [
                'customer_id' => $customerId,
                'store_id' => $storeId,
                'order_number' => 'ORD-'.mb_str_pad((string) $orderNumber++, 6, '0', STR_PAD_LEFT),
                'status' => $status,
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

    // Week starts on Saturday (Feb 28, 2026)
    $saturday = now()->startOfWeek(Carbon::SATURDAY);
    $sunday = $saturday->copy()->addDay();
    $monday = $saturday->copy()->addDays(2);
    $tuesday = $saturday->copy()->addDays(3);

    // Saturday: 2 pending, 1 preparing, 3 completed
    $insertOrders(2, 'pending', $saturday->copy()->addHours(10));
    $insertOrders(1, 'preparing', $saturday->copy()->addHours(11));
    $insertOrders(3, 'completed', $saturday->copy()->addHours(12));

    // Sunday: 1 pending, 2 preparing, 1 completed
    $insertOrders(1, 'pending', $sunday->copy()->addHours(9));
    $insertOrders(2, 'preparing', $sunday->copy()->addHours(10));
    $insertOrders(1, 'completed', $sunday->copy()->addHours(14));

    // Monday: 3 pending, 0 preparing, 2 completed
    $insertOrders(3, 'pending', $monday->copy()->addHours(8));
    $insertOrders(2, 'completed', $monday->copy()->addHours(16));

    // Tuesday: 1 pending, 1 preparing, 4 completed
    $insertOrders(1, 'pending', $tuesday->copy()->addHours(7));
    $insertOrders(1, 'preparing', $tuesday->copy()->addHours(13));
    $insertOrders(4, 'completed', $tuesday->copy()->addHours(17));

    // Orders with other statuses (should not be counted)
    $insertOrders(2, 'accepted', $saturday->copy()->addHours(14));
    $insertOrders(1, 'cancelled', $sunday->copy()->addHours(15));

    // Orders outside the week (should not be counted)
    $insertOrders(5, 'pending', $saturday->copy()->subWeek());

    $response = getJson('/api/v1/sm-orders/hourly-count');

    $response->assertOk();
    $response->assertJson([
        'data' => [
            'saturday' => [
                'pending' => 2,
                'preparing' => 1,
                'completed' => 3,
            ],
            'sunday' => [
                'pending' => 1,
                'preparing' => 2,
                'completed' => 1,
            ],
            'monday' => [
                'pending' => 3,
                'preparing' => 0,
                'completed' => 2,
            ],
            'tuesday' => [
                'pending' => 1,
                'preparing' => 1,
                'completed' => 4,
            ],
            'wednesday' => [
                'pending' => 0,
                'preparing' => 0,
                'completed' => 0,
            ],
            'thursday' => [
                'pending' => 0,
                'preparing' => 0,
                'completed' => 0,
            ],
            'friday' => [
                'pending' => 0,
                'preparing' => 0,
                'completed' => 0,
            ],
        ],
    ]);

    Carbon::setTestNow();
});
