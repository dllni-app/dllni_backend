<?php

declare(strict_types=1);

use Database\Factories\SmOfferFactory;
use Database\Factories\SmOfferProductFactory;
use Database\Factories\SmProductFactory;
use Modules\Supermarket\Models\SmStore;

it('returns customer-safe supermarket offer details with linked products', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'is_temporarily_closed' => false,
        'suspension_until' => null,
    ]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'أرز بسمتي',
        'price' => 100,
        'is_available' => true,
    ]);
    $offer = SmOfferFactory::new()->create([
        'store_id' => $store->id,
        'name' => 'عرض نهاية الأسبوع',
        'discount_percent' => 20,
        'discount_value' => null,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDays(3),
        'is_active' => true,
    ]);
    SmOfferProductFactory::new()->create([
        'offer_id' => $offer->id,
        'product_id' => $product->id,
        'offer_price' => 80,
        'max_quantity' => 4,
    ]);

    $response = $this->getJson("/api/v1/user/supermarket/offers/{$offer->id}");

    $response->assertOk()
        ->assertJsonPath('data.id', $offer->id)
        ->assertJsonPath('data.storeId', $store->id)
        ->assertJsonPath('data.name', 'عرض نهاية الأسبوع')
        ->assertJsonPath('data.discountPercent', 20)
        ->assertJsonPath('data.offerProducts.0.productId', $product->id)
        ->assertJsonPath('data.offerProducts.0.offerPrice', '80.00')
        ->assertJsonPath('data.offerProducts.0.maxQuantity', 4)
        ->assertJsonPath('data.offerProducts.0.product.name', 'أرز بسمتي');

    expect($response->json('data.affectedOrdersCount'))->toBeNull();
});

it('hides inactive supermarket offers from public details', function (): void {
    $store = SmStore::factory()->create([
        'is_active' => true,
        'is_temporarily_closed' => false,
        'suspension_until' => null,
    ]);
    $offer = SmOfferFactory::new()->create([
        'store_id' => $store->id,
        'is_active' => false,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
    ]);

    $this->getJson("/api/v1/user/supermarket/offers/{$offer->id}")
        ->assertNotFound();
});
