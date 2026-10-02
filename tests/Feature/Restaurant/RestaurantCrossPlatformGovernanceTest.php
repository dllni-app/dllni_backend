<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\InventoryItem;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\PromoCode;
use Modules\Resturants\Models\Restaurant;

beforeEach(function (): void {
    $this->owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);

    $this->restaurant = Restaurant::factory()->create([
        'user_id' => $this->owner->id,
    ]);

    $this->otherRestaurant = Restaurant::factory()->create();

    Sanctum::actingAs($this->owner);
});

it('scopes legacy categories offers coupons inventory and products to the owned restaurant', function (): void {
    $ownCategory = Category::factory()->create(['restaurant_id' => $this->restaurant->id]);
    $otherCategory = Category::factory()->create(['restaurant_id' => $this->otherRestaurant->id]);

    $ownProduct = Product::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'category_id' => $ownCategory->id,
    ]);
    $otherProduct = Product::factory()->create([
        'restaurant_id' => $this->otherRestaurant->id,
        'category_id' => $otherCategory->id,
    ]);

    $ownOffer = Offer::query()->create([
        'restaurant_id' => $this->restaurant->id,
        'name' => 'Own Offer',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'is_active' => true,
    ]);
    $otherOffer = Offer::query()->create([
        'restaurant_id' => $this->otherRestaurant->id,
        'name' => 'Other Offer',
        'discount_type' => 'percentage',
        'discount_value' => 20,
        'is_active' => true,
    ]);

    $ownPromo = PromoCode::query()->create([
        'restaurant_id' => $this->restaurant->id,
        'code' => 'OWN10',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'is_active' => true,
    ]);
    $otherPromo = PromoCode::query()->create([
        'restaurant_id' => $this->otherRestaurant->id,
        'code' => 'OTHER10',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'is_active' => true,
    ]);

    $ownInventory = InventoryItem::query()->create([
        'restaurant_id' => $this->restaurant->id,
        'name' => 'Own Stock',
        'unit' => 'kg',
        'quantity' => 10,
        'minimum_limit' => 2,
    ]);
    $otherInventory = InventoryItem::query()->create([
        'restaurant_id' => $this->otherRestaurant->id,
        'name' => 'Other Stock',
        'unit' => 'kg',
        'quantity' => 10,
        'minimum_limit' => 2,
    ]);

    $this->getJson('/api/v1/categories')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownCategory->id);
    $this->getJson("/api/v1/categories/{$otherCategory->id}")->assertNotFound();

    $this->getJson('/api/v1/offers')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownOffer->id);
    $this->getJson("/api/v1/offers/{$otherOffer->id}")->assertNotFound();

    $this->getJson('/api/v1/promo-codes')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownPromo->id);
    $this->getJson("/api/v1/promo-codes/{$otherPromo->id}")->assertNotFound();

    $this->getJson('/api/v1/inventory-items')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownInventory->id);
    $this->getJson("/api/v1/inventory-items/{$otherInventory->id}")->assertNotFound();

    $this->getJson("/api/v1/products?filter[restaurantId]={$this->otherRestaurant->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownProduct->id);

    $this->getJson("/api/v1/products/{$otherProduct->id}")->assertForbidden();
});

it('forces newly created legacy categories to the authenticated restaurant', function (): void {
    $response = $this->postJson('/api/v1/categories', [
        'restaurantId' => $this->otherRestaurant->id,
        'name' => 'Scoped Category',
        'slug' => 'scoped-category',
        'sortOrder' => 1,
    ]);

    $response->assertCreated();

    $this->assertDatabaseHas('categories', [
        'id' => $response->json('data.id'),
        'restaurant_id' => $this->restaurant->id,
        'name' => 'Scoped Category',
    ]);
});

it('rejects inventory links to products owned by another restaurant', function (): void {
    $otherCategory = Category::factory()->create(['restaurant_id' => $this->otherRestaurant->id]);
    $otherProduct = Product::factory()->create([
        'restaurant_id' => $this->otherRestaurant->id,
        'category_id' => $otherCategory->id,
    ]);

    $this->postJson('/api/v1/inventory-items', [
        'name' => 'Unsafe Stock',
        'unit' => 'kg',
        'quantity' => 5,
        'minimumLimit' => 1,
        'productIds' => [$otherProduct->id],
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['products']);
});

it('does not expose generic order create update or delete routes', function (): void {
    $order = Order::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Pending->value,
    ]);

    $this->postJson('/api/v1/orders', [])->assertMethodNotAllowed();
    $this->putJson("/api/v1/orders/{$order->id}", ['totalAmount' => 1])->assertMethodNotAllowed();
    $this->deleteJson("/api/v1/orders/{$order->id}")->assertMethodNotAllowed();
});

it('prevents restaurant owners from changing platform governance fields', function (): void {
    $this->restaurant->forceFill([
        'reputation_score' => 88,
        'warning_count' => 2,
        'is_active' => true,
        'is_featured' => false,
    ])->save();

    $this->putJson('/api/v1/restaurant-owner/restaurant', [
        'name' => 'Allowed Name',
        'reputationScore' => 1,
        'warningCount' => 99,
        'isActive' => false,
        'isFeatured' => true,
    ])->assertUnprocessable()
        ->assertJsonValidationErrors([
            'reputationScore',
            'warningCount',
            'isActive',
            'isFeatured',
        ]);

    $restaurant = $this->restaurant->fresh();

    expect((int) $restaurant->reputation_score)->toBe(88)
        ->and((int) $restaurant->warning_count)->toBe(2)
        ->and((bool) $restaurant->is_active)->toBeTrue()
        ->and((bool) $restaurant->is_featured)->toBeFalse();
});

it('keeps governance and owner identity out of the customer restaurant payload', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->restaurant->forceFill([
        'is_active' => true,
        'reputation_score' => 12,
        'warning_count' => 7,
        'visibility_score' => 3,
        'manual_visibility_override' => true,
    ])->save();

    $payload = $this->getJson("/api/v1/user/restaurants/{$this->restaurant->id}")
        ->assertOk()
        ->json('restaurant');

    expect($payload)->toBeArray()
        ->and($payload)->not->toHaveKeys([
            'userId',
            'user',
            'reputationScore',
            'warningCount',
            'visibilityScore',
            'manualVisibilityOverride',
            'suspensionUntil',
            'documents',
            'reputationLogs',
            'penalties',
        ]);
});
