<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Carbon\Carbon;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmOfferFactory;
use Database\Factories\SmOfferProductFactory;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmOrderItemFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('returns top selling products block for the authenticated owner store', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 4, 27, 12, 0, 0));

    $owner = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);
    Sanctum::actingAs($owner);

    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $owner->id,
        'name' => 'Owner Market',
    ]);

    $category = SmCategoryFactory::new()->create([
        'store_id' => $store->id,
    ]);

    $productA = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Product A',
    ]);

    $productB = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'name' => 'Product B',
    ]);

    $offerA = SmOfferFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'Family 25',
        'offer_type' => 'Discount',
        'discount_percent' => 25,
        'discount_value' => null,
        'starts_at' => now()->copy()->subDay(),
        'ends_at' => now()->copy()->addDay(),
    ]);

    $offerB = SmOfferFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'Secondary Offer',
        'offer_type' => 'Discount',
        'discount_percent' => 10,
        'discount_value' => null,
        'starts_at' => now()->copy()->subDay(),
        'ends_at' => now()->copy()->addDay(),
    ]);

    SmOfferProductFactory::new()->create([
        'offer_id' => $offerA->id,
        'product_id' => $productA->id,
    ]);

    SmOfferProductFactory::new()->create([
        'offer_id' => $offerB->id,
        'product_id' => $productB->id,
    ]);

    $orderOne = SmOrderFactory::new()->create([
        'store_id' => $store->id,
        'status' => 'completed',
        'total_amount' => 100,
        'discount_amount' => 10,
        'created_at' => now(),
    ]);

    SmOrderItemFactory::new()->create([
        'order_id' => $orderOne->id,
        'product_id' => $productA->id,
        'quantity' => 3,
        'total_price' => 60,
    ]);

    $orderTwo = SmOrderFactory::new()->create([
        'store_id' => $store->id,
        'status' => 'accepted',
        'total_amount' => 60,
        'discount_amount' => 0,
        'created_at' => now(),
    ]);

    SmOrderItemFactory::new()->create([
        'order_id' => $orderTwo->id,
        'product_id' => $productA->id,
        'quantity' => 2,
        'total_price' => 40,
    ]);

    SmOrderItemFactory::new()->create([
        'order_id' => $orderTwo->id,
        'product_id' => $productB->id,
        'quantity' => 1,
        'total_price' => 20,
    ]);

    $cancelledOrder = SmOrderFactory::new()->create([
        'store_id' => $store->id,
        'status' => 'cancelled',
        'total_amount' => 50,
        'discount_amount' => 0,
        'created_at' => now(),
    ]);

    SmOrderItemFactory::new()->create([
        'order_id' => $cancelledOrder->id,
        'product_id' => $productB->id,
        'quantity' => 9,
        'total_price' => 90,
    ]);

    $otherOwner = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);

    $otherStore = SmStoreFactory::new()->create([
        'owner_user_id' => $otherOwner->id,
    ]);

    $otherCategory = SmCategoryFactory::new()->create([
        'store_id' => $otherStore->id,
    ]);

    $otherProduct = SmProductFactory::new()->create([
        'store_id' => $otherStore->id,
        'category_id' => $otherCategory->id,
        'name' => 'Other Store Product',
    ]);

    $otherOrder = SmOrderFactory::new()->create([
        'store_id' => $otherStore->id,
        'status' => 'completed',
        'total_amount' => 999,
        'created_at' => now(),
    ]);

    SmOrderItemFactory::new()->create([
        'order_id' => $otherOrder->id,
        'product_id' => $otherProduct->id,
        'quantity' => 99,
        'total_price' => 999,
    ]);

    $response = getJson('/api/v1/store-owner/dashboard/top-selling-products');

    $response->assertOk();
    $response->assertJsonPath('supermarket.id', $store->id);
    $response->assertJsonPath('supermarket.name', 'Owner Market');
    $response->assertJsonPath('range.key', 'today');
    $response->assertJsonPath('range.from', '2026-04-27');
    $response->assertJsonPath('range.to', '2026-04-27');

    $response->assertJsonPath('topProducts.0.productId', $productA->id);
    $response->assertJsonPath('topProducts.0.name', 'Product A');
    $response->assertJsonPath('topProducts.0.quantity', 5);
    $response->assertJsonPath('topProducts.0.revenue', 100);

    $response->assertJsonPath('offersImpact.discountedOrdersCount', 2);
    $response->assertJsonPath('offersImpact.conversionRatePercent', 66.67);
    $response->assertJsonPath('offersImpact.discountedRevenue', 160);
    $response->assertJsonPath('offersImpact.totalSavings', 10);
    $response->assertJsonPath('offersImpact.ordersUsedOffers', 2);
    $response->assertJsonPath('offersImpact.utilizationRatePercent', 66.67);
    $response->assertJsonPath('offersImpact.offersRevenue', 160);

    $response->assertJsonPath('bestOfferPerformance.offerId', $offerA->id);
    $response->assertJsonPath('bestOfferPerformance.name', 'Family 25');
    $response->assertJsonPath('bestOfferPerformance.discountPercent', 25);
    $response->assertJsonPath('bestOfferPerformance.usesCount', 2);
    $response->assertJsonPath('bestOfferPerformance.revenue', 160);
});

it('validates custom range query params', function (): void {
    $owner = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);
    Sanctum::actingAs($owner);

    SmStoreFactory::new()->create([
        'owner_user_id' => $owner->id,
    ]);

    $response = getJson('/api/v1/store-owner/dashboard/top-selling-products?range=custom');

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['from', 'to']);
});

it('returns forbidden when seller has no store', function (): void {
    $owner = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);
    Sanctum::actingAs($owner);

    $response = getJson('/api/v1/store-owner/dashboard/top-selling-products');

    $response->assertForbidden();
});
