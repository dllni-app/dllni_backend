<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmOfferFactory;
use Database\Factories\SmOfferProductFactory;
use Database\Factories\SmProductFactory;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Favorite;
use Modules\Supermarket\Models\SmModifier;
use Modules\Supermarket\Models\SmModifierGroup;
use Modules\Supermarket\Models\SmStore;

it('lists supermarket products with pagination', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    SmProductFactory::new()->count(3)->create([
        'store_id' => $store->id,
        'is_available' => true,
    ]);

    $response = $this->getJson('/api/v1/user/supermarket/products/search');

    $response->assertOk()->assertJsonStructure([
        'data',
        'links',
        'meta',
    ]);
});

it('filters supermarket products by search query param', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'Fresh Bread',
        'is_available' => true,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'Chocolate Milk',
        'is_available' => true,
    ]);

    $response = $this->getJson('/api/v1/user/supermarket/products/search?search=bread');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();

    expect($names)->toContain('Fresh Bread');
    expect($names)->not->toContain('Chocolate Milk');
});

it('excludes products from unavailable inventory or inactive stores', function (): void {
    $activeStore = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $inactiveStore = SmStore::factory()->create([
        'is_active' => false,
        'suspension_until' => null,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $activeStore->id,
        'name' => 'Visible Product',
        'is_available' => true,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $activeStore->id,
        'name' => 'Unavailable Product',
        'is_available' => false,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $inactiveStore->id,
        'name' => 'Inactive Store Product',
        'is_available' => true,
    ]);

    $response = $this->getJson('/api/v1/user/supermarket/products/search');

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();

    expect($names)->toContain('Visible Product');
    expect($names)->not->toContain('Unavailable Product');
    expect($names)->not->toContain('Inactive Store Product');
});

it('shows a supermarket product by id', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Showcase Product',
        'price' => 40,
        'discounted_price' => 25,
        'is_available' => true,
    ]);

    Favorite::create([
        'user_id' => $user->id,
        'favorable_type' => $product->getMorphClass(),
        'favorable_id' => $product->id,
    ]);

    $offer = SmOfferFactory::new()->create([
        'store_id' => $store->id,
        'is_active' => true,
        'discount_value' => 12,
        'ends_at' => now()->addDay(),
    ]);

    SmOfferProductFactory::new()->create([
        'offer_id' => $offer->id,
        'product_id' => $product->id,
    ]);

    $response = $this->getJson("/api/v1/user/supermarket/products/{$product->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.originalPrice', '40.00')
        ->assertJsonPath('data.finalPrice', '25.00')
        ->assertJsonPath('data.hasDiscount', true)
        ->assertJsonPath('product.id', $product->id)
        ->assertJsonPath('product.name', 'Showcase Product')
        ->assertJsonPath('product.isFavorite', true)
        ->assertJsonPath('product.offers.0.id', $offer->id);
});

it('does not show unavailable supermarket products', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'is_available' => false,
    ]);

    $this->getJson("/api/v1/user/supermarket/products/{$product->id}")->assertNotFound();
});

it('returns selectable options in supermarket product show response', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'is_available' => true,
    ]);

    $group = SmModifierGroup::create([
        'store_id' => $store->id,
        'name' => 'Optional Add-ons',
        'is_required' => false,
        'min_selections' => 0,
        'max_selections' => 3,
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $group->products()->attach($product->id);

    $modifier = SmModifier::create([
        'modifier_group_id' => $group->id,
        'name' => 'Extra cheese',
        'price' => 3,
        'sort_order' => 1,
        'is_available' => true,
    ]);

    $response = $this->getJson("/api/v1/user/supermarket/products/{$product->id}");

    $response->assertOk()
        ->assertJsonPath('data.options.0.id', $group->id)
        ->assertJsonPath('data.options.0.name', 'Optional Add-ons')
        ->assertJsonPath('data.options.0.modifiers.0.id', $modifier->id)
        ->assertJsonPath('data.options.0.modifiers.0.name', 'Extra cheese')
        ->assertJsonPath('data.options.0.modifiers.0.price', 3)
        ->assertJsonPath('product.options.0.id', $group->id);
});

it('returns similar products by the selected product title', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);

    $selectedProduct = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Fresh Milk',
        'is_available' => true,
    ]);

    $matchingProduct = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Fresh Milk 1L',
        'is_available' => true,
    ]);

    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Chocolate Drink',
        'is_available' => true,
    ]);

    $response = $this->getJson("/api/v1/user/supermarket/products/{$selectedProduct->id}/similar");

    $response->assertOk();
    $names = collect($response->json('data'))->pluck('name')->all();

    expect($names)->toContain('Fresh Milk 1L');
    expect($names)->not->toContain('Fresh Milk');
    expect($names)->not->toContain('Chocolate Drink');
});

it('supports supermarket product compare endpoint alias', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);

    $selectedProduct = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Fresh Milk',
        'is_available' => true,
    ]);

    $cheaper = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Fresh Milk 500ml',
        'price' => 10,
        'discounted_price' => null,
        'is_available' => true,
    ]);

    $expensive = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Fresh Milk 1L',
        'price' => 20,
        'discounted_price' => null,
        'is_available' => true,
    ]);

    $response = $this->getJson("/api/v1/user/supermarket/products/{$selectedProduct->id}/compare?perPage=5");

    $response->assertOk();
    $response->assertJsonPath('meta.per_page', 5);
    $ids = collect($response->json('data'))->pluck('id')->values()->all();

    expect($ids)->toBe([$cheaper->id, $expensive->id]);
});

it('marks similar products as favorite for authenticated users', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStore::factory()->create([
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $category = SmCategoryFactory::new()->create(['store_id' => $store->id]);

    $selectedProduct = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Fresh Milk',
        'is_available' => true,
    ]);

    $favoritedProduct = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Fresh Milk Premium',
        'is_available' => true,
    ]);

    Favorite::create([
        'user_id' => $user->id,
        'favorable_type' => $favoritedProduct->getMorphClass(),
        'favorable_id' => $favoritedProduct->id,
    ]);

    $response = $this->getJson("/api/v1/user/supermarket/products/{$selectedProduct->id}/similar");

    $response->assertOk();
    $response->assertJsonPath('data.0.id', $favoritedProduct->id);
    $response->assertJsonPath('data.0.isFavorite', true);
});
