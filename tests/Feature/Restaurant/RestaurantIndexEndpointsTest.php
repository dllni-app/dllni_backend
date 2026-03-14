<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\PromoCode;
use Modules\Resturants\Models\RestaurantDocument;
use Modules\Resturants\Models\RestaurantOrderDispute;
use Modules\Resturants\Models\RestaurantPenalty;
use Modules\Resturants\Models\RestaurantRecurringOrder;
use Modules\Resturants\Models\RestaurantReputationLog;
use Modules\Resturants\Models\RestaurantRole;
use Modules\Resturants\Models\RestaurantStaff;
use Modules\Resturants\Models\Review;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('lists offers', function () {
    Offer::create([
        'restaurant_id' => Modules\Resturants\Models\Restaurant::factory()->create()->id,
        'name' => 'Summer Sale',
        'discount_type' => 'percentage',
        'discount_value' => 20,
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/v1/offers');

    $response->assertOk();
    expect($response->json('data'))->toBeArray();
});

it('lists promo codes', function () {
    PromoCode::create([
        'restaurant_id' => Modules\Resturants\Models\Restaurant::factory()->create()->id,
        'code' => 'SAVE10',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'is_active' => true,
    ]);

    $response = $this->getJson('/api/v1/promo-codes');

    $response->assertOk();
    expect($response->json('data'))->toBeArray();
});

it('lists restaurant order disputes', function () {
    $order = Modules\Resturants\Models\Order::factory()->create();
    RestaurantOrderDispute::create([
        'order_id' => $order->id,
        'user_id' => $order->user_id,
        'ticket_number' => 'TKT-'.uniqid(),
        'status' => 'open',
    ]);

    $response = $this->getJson('/api/v1/restaurant-order-disputes');

    $response->assertOk();
    expect($response->json('data'))->toBeArray();
});

it('lists restaurant documents', function () {
    RestaurantDocument::create([
        'restaurant_id' => Modules\Resturants\Models\Restaurant::factory()->create()->id,
        'document_type' => 'identity',
        'verification_status' => 'pending',
    ]);

    $response = $this->getJson('/api/v1/restaurant-documents');

    $response->assertOk();
    expect($response->json('data'))->toBeArray();
});

it('lists restaurant reputation logs', function () {
    RestaurantReputationLog::create([
        'restaurant_id' => Modules\Resturants\Models\Restaurant::factory()->create()->id,
        'score_delta' => -5,
        'reason' => 'Late delivery',
    ]);

    $response = $this->getJson('/api/v1/restaurant-reputation-logs');

    $response->assertOk();
    expect($response->json('data'))->toBeArray();
});

it('lists restaurant penalties', function () {
    RestaurantPenalty::create([
        'restaurant_id' => Modules\Resturants\Models\Restaurant::factory()->create()->id,
        'penalty_type' => 'warning',
        'reason' => 'Policy violation',
    ]);

    $response = $this->getJson('/api/v1/restaurant-penalties');

    $response->assertOk();
    expect($response->json('data'))->toBeArray();
});

it('lists restaurant staff', function () {
    $restaurant = Modules\Resturants\Models\Restaurant::factory()->create();
    $role = RestaurantRole::create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Manager',
        'slug' => 'manager',
    ]);
    RestaurantStaff::create([
        'restaurant_id' => $restaurant->id,
        'user_id' => User::factory()->create()->id,
        'restaurant_role_id' => $role->id,
    ]);

    $response = $this->getJson('/api/v1/restaurant-staff');

    $response->assertOk();
    expect($response->json('data'))->toBeArray();
});


it('lists restaurant recurring orders', function () {
    RestaurantRecurringOrder::create([
        'user_id' => User::factory()->create()->id,
        'restaurant_id' => Modules\Resturants\Models\Restaurant::factory()->create()->id,
        'status' => 'active',
        'frequency' => 'weekly',
    ]);

    $response = $this->getJson('/api/v1/restaurant-recurring-orders');

    $response->assertOk();
    expect($response->json('data'))->toBeArray();
});

it('lists reviews', function () {
    $order = Modules\Resturants\Models\Order::factory()->create();
    Review::create([
        'user_id' => $order->user_id,
        'order_id' => $order->id,
        'restaurant_id' => $order->restaurant_id,
        'rating' => 5,
    ]);

    $response = $this->getJson('/api/v1/reviews');

    $response->assertOk();
    expect($response->json('data'))->toBeArray();
});
