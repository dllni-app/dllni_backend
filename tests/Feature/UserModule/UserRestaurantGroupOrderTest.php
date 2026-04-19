<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantGroupOrder;
use Modules\User\Events\RestaurantGroupOrderUpdated;
use function Pest\Laravel\postJson;

it('requires authentication to create a group order', function (): void {
    $restaurant = Restaurant::factory()->create(['is_active' => true]);

    postJson('/api/v1/user/restaurants/group-orders', [
        'restaurantId' => $restaurant->id,
        'durationMinutes' => 30,
    ])->assertUnauthorized();
});

it('creates a group order and returns payload with share token', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);

    $response = postJson('/api/v1/user/restaurants/group-orders', [
        'restaurantId' => $restaurant->id,
        'name' => 'Team Lunch',
        'durationMinutes' => 30,
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.groupOrder.status', 'active')
        ->assertJsonPath('data.groupOrder.restaurantId', $restaurant->id)
        ->assertJsonPath('data.groupOrder.name', 'Team Lunch');

    $token = (string) $response->json('data.groupOrder.shareToken');
    expect(strlen($token))->toBe(32);

    expect(RestaurantGroupOrder::query()->where('share_token', $token)->exists())->toBeTrue();
});

it('enforces only one active group order per organizer', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);

    postJson('/api/v1/user/restaurants/group-orders', [
        'restaurantId' => $restaurant->id,
        'durationMinutes' => 30,
    ])->assertCreated();

    postJson('/api/v1/user/restaurants/group-orders', [
        'restaurantId' => $restaurant->id,
        'durationMinutes' => 30,
    ])->assertUnprocessable();
});

it('joins by token and auto places when all joined participants submit', function (): void {
    Event::fake([RestaurantGroupOrderUpdated::class]);

    $organizer = User::factory()->create();
    $guest = User::factory()->create();

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $productA = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);
    $productB = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);

    Sanctum::actingAs($organizer);

    $create = postJson('/api/v1/user/restaurants/group-orders', [
        'restaurantId' => $restaurant->id,
        'durationMinutes' => 30,
    ])->assertCreated();

    $groupOrderId = (int) $create->json('data.groupOrder.id');
    $shareToken = (string) $create->json('data.groupOrder.shareToken');

    postJson('/api/v1/user/restaurants/group-orders/' . $groupOrderId . '/items', [
        'productId' => $productA->id,
        'quantity' => 1,
    ])->assertCreated();

    postJson('/api/v1/user/restaurants/group-orders/' . $groupOrderId . '/submit')
        ->assertSuccessful()
        ->assertJsonPath('data.groupOrder.status', 'placed')
        ->assertJsonPath('data.groupOrder.placedOrderId', fn($id) => is_int($id) && $id > 0);

    Sanctum::actingAs($guest);

    postJson('/api/v1/user/restaurants/group-orders/join', [
        'shareToken' => $shareToken,
    ])->assertUnprocessable();

    Sanctum::actingAs($organizer);

    postJson('/api/v1/user/restaurants/group-orders', [
        'restaurantId' => $restaurant->id,
        'durationMinutes' => 30,
    ])->assertCreated();

    $newOrder = RestaurantGroupOrder::query()->latest('id')->firstOrFail();

    Sanctum::actingAs($guest);

    postJson('/api/v1/user/restaurants/group-orders/join', [
        'shareToken' => $newOrder->share_token,
    ])->assertSuccessful();

    postJson('/api/v1/user/restaurants/group-orders/' . $newOrder->id . '/items', [
        'productId' => $productB->id,
        'quantity' => 2,
    ])->assertCreated();

    Sanctum::actingAs($organizer);

    postJson('/api/v1/user/restaurants/group-orders/' . $newOrder->id . '/items', [
        'productId' => $productA->id,
        'quantity' => 1,
    ])->assertCreated();

    postJson('/api/v1/user/restaurants/group-orders/' . $newOrder->id . '/submit')
        ->assertSuccessful()
        ->assertJsonPath('data.groupOrder.status', 'active');

    Sanctum::actingAs($guest);

    postJson('/api/v1/user/restaurants/group-orders/' . $newOrder->id . '/submit')
        ->assertSuccessful()
        ->assertJsonPath('data.groupOrder.status', 'placed');

    $newOrder->refresh();
    expect($newOrder->placed_order_id)->not->toBeNull();
    expect(Order::query()->whereKey($newOrder->placed_order_id)->exists())->toBeTrue();

    Event::assertDispatched(RestaurantGroupOrderUpdated::class);
});
