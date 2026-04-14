<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

describe('Products with Active Offers Endpoint', function (): void {
    it('returns products with active offers without authentication', function (): void {
        $restaurant = Restaurant::factory()->create(['is_active' => true]);
        $offer = Offer::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_active' => true,
            'ends_at' => now()->addDays(5),
        ]);

        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_available' => true,
        ]);
        $product->offers()->attach($offer);

        $response = $this->getJson('/api/v1/user/restaurants/products/with-offers');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'displayPrice',
                        'isFavorite',
                        'isMostOrdered',
                        'popularOrdersCount',
                        'activeOffers',
                    ],
                ],
            ]);
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.activeOffers'))->toHaveCount(1);
    });

    it('returns paginated products with active offers', function (): void {
        $restaurant = Restaurant::factory()->create(['is_active' => true]);
        $offer = Offer::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_active' => true,
            'ends_at' => now()->addDays(5),
        ]);

        Product::factory(20)->create([
            'restaurant_id' => $restaurant->id,
            'is_available' => true,
        ])->each(function ($product) use ($offer): void {
            $product->offers()->attach($offer);
        });

        $response = $this->getJson('/api/v1/user/restaurants/products/with-offers?per_page=10');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(10);
        expect($response->json('meta.total'))->toBe(20);
    });

    it('filters products by restaurant id', function (): void {
        $restaurant1 = Restaurant::factory()->create(['is_active' => true]);
        $restaurant2 = Restaurant::factory()->create(['is_active' => true]);

        $offer1 = Offer::factory()->create([
            'restaurant_id' => $restaurant1->id,
            'is_active' => true,
            'ends_at' => now()->addDays(5),
        ]);

        $offer2 = Offer::factory()->create([
            'restaurant_id' => $restaurant2->id,
            'is_active' => true,
            'ends_at' => now()->addDays(5),
        ]);

        $product1 = Product::factory()->create([
            'restaurant_id' => $restaurant1->id,
            'is_available' => true,
        ]);
        $product1->offers()->attach($offer1);

        $product2 = Product::factory()->create([
            'restaurant_id' => $restaurant2->id,
            'is_available' => true,
        ]);
        $product2->offers()->attach($offer2);

        $response = $this->getJson("/api/v1/user/restaurants/products/with-offers?restaurant_id={$restaurant1->id}");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.id'))->toBe($product1->id);
    });

    it('only returns products with active offers', function (): void {
        $restaurant = Restaurant::factory()->create(['is_active' => true]);
        $activeOffer = Offer::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_active' => true,
            'ends_at' => now()->addDays(5),
        ]);
        $inactiveOffer = Offer::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_active' => false,
            'ends_at' => now()->addDays(5),
        ]);
        $expiredOffer = Offer::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_active' => true,
            'ends_at' => now()->subDays(1),
        ]);

        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_available' => true,
        ]);
        $product->offers()->attach([$activeOffer->id, $inactiveOffer->id, $expiredOffer->id]);

        $response = $this->getJson('/api/v1/user/restaurants/products/with-offers');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.activeOffers'))->toHaveCount(1);
        expect($response->json('data.0.activeOffers.0.id'))->toBe($activeOffer->id);
    });

    it('includes offer discount details in response', function (): void {
        $restaurant = Restaurant::factory()->create(['is_active' => true]);
        $offer = Offer::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_active' => true,
            'discount_type' => 'percentage',
            'discount_value' => 20.00,
            'ends_at' => now()->addDays(5),
        ]);

        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_available' => true,
        ]);
        $product->offers()->attach($offer);

        $response = $this->getJson('/api/v1/user/restaurants/products/with-offers');

        $response->assertOk();
        expect($response->json('data.0.activeOffers.0'))->toHaveKeys([
            'id',
            'name',
            'discountType',
            'discountValue',
            'badgeText',
            'startsAt',
            'endsAt',
            'urgencyTag',
            'isActive',
        ]);
        expect((float) $response->json('data.0.activeOffers.0.discountValue'))->toBe(20.0);
        expect($response->json('data.0.activeOffers.0.badgeText'))->toBe('20%');
        expect($response->json('data.0.activeOffers.0.name'))->toBe($offer->name);
        expect($response->json('data.0.activeOffers.0.isActive'))->toBeTrue();
    });

    it('marks products as favorite for authenticated users', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $restaurant = Restaurant::factory()->create(['is_active' => true]);
        $offer = Offer::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_active' => true,
            'ends_at' => now()->addDays(5),
        ]);

        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_available' => true,
        ]);
        $product->offers()->attach($offer);

        // Add to favorites
        $user->favorites()->create([
            'favorable_type' => 'Modules\\Resturants\\Models\\Product',
            'favorable_id' => $product->id,
        ]);

        $response = $this->getJson('/api/v1/user/restaurants/products/with-offers');

        $response->assertOk();
        expect($response->json('data.0.isFavorite'))->toBeTrue();
    });

    it('only returns available products', function (): void {
        $restaurant = Restaurant::factory()->create(['is_active' => true]);
        $offer = Offer::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_active' => true,
            'ends_at' => now()->addDays(5),
        ]);

        $availableProduct = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_available' => true,
        ]);
        $availableProduct->offers()->attach($offer);

        $unavailableProduct = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_available' => false,
        ]);
        $unavailableProduct->offers()->attach($offer);

        $response = $this->getJson('/api/v1/user/restaurants/products/with-offers');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.id'))->toBe($availableProduct->id);
    });

    it('validates per_page parameter', function (): void {
        $response = $this->getJson('/api/v1/user/restaurants/products/with-offers?per_page=150');
        $response->assertUnprocessable();

        $response = $this->getJson('/api/v1/user/restaurants/products/with-offers?per_page=0');
        $response->assertUnprocessable();
    });

    it('marks product as most ordered when it has enough recent successful orders', function (): void {
        $restaurant = Restaurant::factory()->create(['is_active' => true]);
        $offer = Offer::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_active' => true,
            'ends_at' => now()->addDays(5),
        ]);

        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_available' => true,
        ]);
        $product->offers()->attach($offer);

        Order::factory()->count(5)->create([
            'restaurant_id' => $restaurant->id,
            'status' => OrderStatus::Completed,
            'created_at' => now()->subDays(3),
        ])->each(function (Order $order) use ($product): void {
            OrderItem::create([
                'order_id' => $order->id,
                'product_id' => $product->id,
                'quantity' => 1,
                'unit_price' => $product->price,
                'total_price' => $product->price,
            ]);
        });

        $response = $this->getJson('/api/v1/user/restaurants/products/with-offers');

        $response->assertOk();
        expect($response->json('data.0.id'))->toBe($product->id);
        expect($response->json('data.0.isMostOrdered'))->toBeTrue();
        expect($response->json('data.0.popularOrdersCount'))->toBe(5);
    });
});

describe('Products by Category Endpoint', function (): void {
    it('returns products filtered by category without authentication', function (): void {
        $restaurant = Restaurant::factory()->create(['is_active' => true]);
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'is_available' => true,
        ]);

        $response = $this->getJson("/api/v1/user/restaurants/products/by-category/{$category->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'category',
                        'activeOffers',
                    ],
                ],
            ]);
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.id'))->toBe($product->id);
    });

    it('returns only products from the specified category', function (): void {
        $restaurant = Restaurant::factory()->create(['is_active' => true]);
        $category1 = Category::factory()->create(['restaurant_id' => $restaurant->id]);
        $category2 = Category::factory()->create(['restaurant_id' => $restaurant->id]);

        $product1 = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category1->id,
            'is_available' => true,
        ]);

        $product2 = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category2->id,
            'is_available' => true,
        ]);

        $response = $this->getJson("/api/v1/user/restaurants/products/by-category/{$category1->id}");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.id'))->toBe($product1->id);
    });

    it('only returns available products in category', function (): void {
        $restaurant = Restaurant::factory()->create(['is_active' => true]);
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

        $availableProduct = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'is_available' => true,
        ]);

        $unavailableProduct = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'is_available' => false,
        ]);

        $response = $this->getJson("/api/v1/user/restaurants/products/by-category/{$category->id}");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.id'))->toBe($availableProduct->id);
    });

    it('includes active offers in products response', function (): void {
        $restaurant = Restaurant::factory()->create(['is_active' => true]);
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

        $offer = Offer::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_active' => true,
            'ends_at' => now()->addDays(5),
        ]);

        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'is_available' => true,
        ]);
        $product->offers()->attach($offer);

        $response = $this->getJson("/api/v1/user/restaurants/products/by-category/{$category->id}");

        $response->assertOk();
        expect($response->json('data.0.activeOffers'))->toHaveCount(1);
        expect($response->json('data.0.activeOffers.0.id'))->toBe($offer->id);
    });

    it('returns 404 for non-existent category', function (): void {
        $response = $this->getJson('/api/v1/user/restaurants/products/by-category/99999');
        $response->assertNotFound();
    });

    it('supports pagination', function (): void {
        $restaurant = Restaurant::factory()->create(['is_active' => true]);
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

        Product::factory(20)->create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'is_available' => true,
        ]);

        $response = $this->getJson("/api/v1/user/restaurants/products/by-category/{$category->id}?per_page=10");

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(10);
        expect($response->json('meta.total'))->toBe(20);
    });

    it('marks products as favorite for authenticated users', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $restaurant = Restaurant::factory()->create(['is_active' => true]);
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);

        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'is_available' => true,
        ]);

        // Add to favorites
        $user->favorites()->create([
            'favorable_type' => 'Modules\\Resturants\\Models\\Product',
            'favorable_id' => $product->id,
        ]);

        $response = $this->getJson("/api/v1/user/restaurants/products/by-category/{$category->id}");

        $response->assertOk();
        expect($response->json('data.0.isFavorite'))->toBeTrue();
    });
});

describe('Enhanced Favorite Products Endpoint', function (): void {
    it('requires authentication', function (): void {
        $this->getJson('/api/v1/user/favorites/products')->assertUnauthorized();
    });

    it('returns favorite products with active offers', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $restaurant = Restaurant::factory()->create(['is_active' => true]);
        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_available' => true,
        ]);

        $offer = Offer::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_active' => true,
            'ends_at' => now()->addDays(5),
        ]);
        $product->offers()->attach($offer);

        $user->favorites()->create([
            'favorable_type' => 'Modules\\Resturants\\Models\\Product',
            'favorable_id' => $product->id,
        ]);

        $response = $this->getJson('/api/v1/user/favorites/products');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(1);
        expect($response->json('data.0.activeOffers'))->toHaveCount(1);
        expect($response->json('data.0.isFavorite'))->toBeTrue();
    });

    it('includes only active offers in favorite products', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $restaurant = Restaurant::factory()->create(['is_active' => true]);
        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_available' => true,
        ]);

        $activeOffer = Offer::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_active' => true,
            'ends_at' => now()->addDays(5),
        ]);
        $inactiveOffer = Offer::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_active' => false,
            'ends_at' => now()->addDays(5),
        ]);

        $product->offers()->attach([$activeOffer->id, $inactiveOffer->id]);

        $user->favorites()->create([
            'favorable_type' => 'Modules\\Resturants\\Models\\Product',
            'favorable_id' => $product->id,
        ]);

        $response = $this->getJson('/api/v1/user/favorites/products');

        $response->assertOk();
        expect($response->json('data.0.activeOffers'))->toHaveCount(1);
        expect($response->json('data.0.activeOffers.0.id'))->toBe($activeOffer->id);
    });

    it('excludes unavailable products', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $restaurant = Restaurant::factory()->create(['is_active' => true]);
        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_available' => false,
        ]);

        $user->favorites()->create([
            'favorable_type' => 'Modules\\Resturants\\Models\\Product',
            'favorable_id' => $product->id,
        ]);

        $response = $this->getJson('/api/v1/user/favorites/products');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(0);
    });

    it('excludes products from inactive restaurants', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $restaurant = Restaurant::factory()->create(['is_active' => false]);
        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'is_available' => true,
        ]);

        $user->favorites()->create([
            'favorable_type' => 'Modules\\Resturants\\Models\\Product',
            'favorable_id' => $product->id,
        ]);

        $response = $this->getJson('/api/v1/user/favorites/products');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(0);
    });

    it('supports pagination', function (): void {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $restaurant = Restaurant::factory()->create(['is_active' => true]);

        Product::factory(20)->create([
            'restaurant_id' => $restaurant->id,
            'is_available' => true,
        ])->each(function ($product) use ($user): void {
            $user->favorites()->create([
                'favorable_type' => 'Modules\\Resturants\\Models\\Product',
                'favorable_id' => $product->id,
            ]);
        });

        $response = $this->getJson('/api/v1/user/favorites/products?perPage=10');

        $response->assertOk();
        expect($response->json('data'))->toHaveCount(10);
        expect($response->json('meta.total'))->toBe(20);
    });
});
