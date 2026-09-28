<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Restaurant;

beforeEach(function () {
    $owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);

    $this->restaurant = Restaurant::factory()->create([
        'user_id' => $owner->id,
    ]);

    Sanctum::actingAs($owner);
});

it('returns daily stats for restaurant from completed orders', function () {
    $statDate = now()->subDay()->toDateString();

    Order::factory()->count(15)->create([
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Completed->value,
        'total_amount' => 30.03,
        'created_at' => now()->subDay()->setTime(12, 0),
        'updated_at' => now()->subDay()->setTime(12, 0),
    ]);

    Order::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Cancelled->value,
        'total_amount' => 999,
        'created_at' => now()->subDay()->setTime(13, 0),
    ]);

    $response = $this->getJson('/api/v1/restaurant/analytics/daily-stats?'.http_build_query([
        'restaurantId' => $this->restaurant->id,
        'dateFrom' => $statDate,
        'dateTo' => $statDate,
    ]));

    $response->assertOk();
    $data = $response->json('data');

    expect($data)->toBeArray()->toHaveCount(1)
        ->and($data[0]['statDate'])->toBe($statDate)
        ->and($data[0]['ordersCount'])->toBe(15)
        ->and((float) $data[0]['revenue'])->toBe(450.45)
        ->and((float) $data[0]['averageOrderValue'])->toBe(30.03);
});

it('validates required params for daily stats', function () {
    $response = $this->getJson('/api/v1/restaurant/analytics/daily-stats');

    $response->assertUnprocessable();
});

it('returns monthly stats for restaurant from completed orders', function () {
    Order::factory()->count(12)->create([
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Completed->value,
        'total_amount' => 30,
        'created_at' => now()->startOfMonth()->addDay(),
        'updated_at' => now()->startOfMonth()->addDay(),
    ]);

    $response = $this->getJson('/api/v1/restaurant/analytics/monthly-stats?'.http_build_query([
        'restaurantId' => $this->restaurant->id,
        'dateFrom' => now()->startOfMonth()->toDateString(),
        'dateTo' => now()->endOfMonth()->toDateString(),
    ]));

    $response->assertOk();

    expect($response->json('data'))->toBeArray()->toHaveCount(1)
        ->and($response->json('data.0.ordersCount'))->toBe(12)
        ->and((float) $response->json('data.0.revenue'))->toBe(360.0)
        ->and((float) $response->json('data.0.averageOrderValue'))->toBe(30.0);
});
