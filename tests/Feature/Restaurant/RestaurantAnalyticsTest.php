<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantDailyStat;
use Modules\Resturants\Models\RestaurantMonthlyStat;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('returns daily stats for restaurant', function () {
    $restaurant = Restaurant::factory()->create();
    $statDate = '2025-01-15';
    RestaurantDailyStat::create([
        'restaurant_id' => $restaurant->id,
        'stat_date' => $statDate,
        'orders_count' => 15,
        'revenue' => 450.50,
        'average_order_value' => 30.03,
    ]);

    $response = $this->getJson('/api/v1/restaurant/analytics/daily-stats?'.http_build_query([
        'restaurantId' => $restaurant->id,
        'dateFrom' => $statDate,
        'dateTo' => $statDate,
    ]));

    $response->assertOk();
    $data = $response->json('data');
    expect($data)->toBeArray()->toHaveCount(1);
    expect($data[0]['statDate'])->toBe($statDate);
    expect($data[0]['ordersCount'])->toBe(15);
    expect((float) $data[0]['revenue'])->toBe(450.5);
});

it('validates required params for daily stats', function () {
    $response = $this->getJson('/api/v1/restaurant/analytics/daily-stats');

    $response->assertUnprocessable();
});

it('returns monthly stats for restaurant', function () {
    $restaurant = Restaurant::factory()->create();
    RestaurantMonthlyStat::create([
        'restaurant_id' => $restaurant->id,
        'stat_year' => (int) now()->year,
        'stat_month' => (int) now()->month,
        'orders_count' => 120,
        'revenue' => 3600,
        'average_order_value' => 30,
    ]);

    $response = $this->getJson('/api/v1/restaurant/analytics/monthly-stats?'.http_build_query([
        'restaurantId' => $restaurant->id,
        'dateFrom' => now()->startOfMonth()->toDateString(),
        'dateTo' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();
    expect($response->json('data'))->toBeArray();
    expect($response->json('data.0.ordersCount'))->toBe(120);
});
