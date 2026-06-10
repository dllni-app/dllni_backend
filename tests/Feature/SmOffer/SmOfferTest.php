<?php

declare(strict_types=1);

use Database\Factories\SmOfferFactory;
use Database\Factories\SmOfferProductFactory;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmOrderItemFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;

beforeEach(function (): void {
    $context = actingAsSupermarketSeller();
    $this->user = $context->user;
    $this->store = $context->store;
});

it('lists offers', function (): void {
    SmOfferFactory::new()->count(3)->create(['store_id' => $this->store->id]);

    $response = $this->getJson('/api/v1/sm-offers?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('creates an offer', function (): void {
    $productOne = SmProductFactory::new()->create(['store_id' => $this->store->id]);
    $productTwo = SmProductFactory::new()->create(['store_id' => $this->store->id]);

    $payload = [
        'storeId' => $this->store->id,
        'name' => 'Summer Sale',
        'offerType' => 'Discount',
        'discountPercent' => 20,
        'isActive' => true,
        'offerProducts' => [
            [
                'productId' => $productOne->id,
            ],
            [
                'productId' => $productTwo->id,
            ],
        ],
    ];

    $response = $this->postJson('/api/v1/sm-offers', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_offers', ['name' => 'Summer Sale']);
    $offerId = $response->json('data.id');

    $this->assertDatabaseHas('sm_offer_products', [
        'offer_id' => $offerId,
        'product_id' => $productOne->id,
    ]);
    $this->assertDatabaseHas('sm_offer_products', [
        'offer_id' => $offerId,
        'product_id' => $productTwo->id,
    ]);
});

it('updates an offer', function (): void {
    $oldProduct = SmProductFactory::new()->create(['store_id' => $this->store->id]);
    $newProduct = SmProductFactory::new()->create(['store_id' => $this->store->id]);

    $offer = SmOfferFactory::new()->create([
        'store_id' => $this->store->id,
        'name' => 'Old Offer',
    ]);
    SmOfferProductFactory::new()->create([
        'offer_id' => $offer->id,
        'product_id' => $oldProduct->id,
    ]);

    $payload = [
        'name' => 'New Offer',
        'offerProducts' => [
            [
                'productId' => $newProduct->id,
                'offerPrice' => 7.75,
                'maxQuantity' => 2,
            ],
        ],
    ];

    $response = $this->putJson("/api/v1/sm-offers/{$offer->id}", $payload);

    $response->assertOk();
    $this->assertDatabaseHas('sm_offers', ['id' => $offer->id, 'name' => 'New Offer']);
    $this->assertDatabaseMissing('sm_offer_products', [
        'offer_id' => $offer->id,
        'product_id' => $oldProduct->id,
    ]);
    $this->assertDatabaseHas('sm_offer_products', [
        'offer_id' => $offer->id,
        'product_id' => $newProduct->id,
    ]);
});

it('deletes an offer', function (): void {
    $offer = SmOfferFactory::new()->create(['store_id' => $this->store->id]);

    $response = $this->deleteJson("/api/v1/sm-offers/{$offer->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_offers', ['id' => $offer->id]);
});

it('returns offer products and affected orders counts', function (): void {
    $otherStore = SmStoreFactory::new()->create();
    $offer = SmOfferFactory::new()->create([
        'store_id' => $this->store->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
    ]);

    $productA = SmProductFactory::new()->create(['store_id' => $this->store->id]);
    $productB = SmProductFactory::new()->create(['store_id' => $this->store->id]);
    $otherProduct = SmProductFactory::new()->create(['store_id' => $this->store->id]);

    SmOfferProductFactory::new()->create([
        'offer_id' => $offer->id,
        'product_id' => $productA->id,
    ]);

    SmOfferProductFactory::new()->create([
        'offer_id' => $offer->id,
        'product_id' => $productB->id,
    ]);

    $affectedOrderOne = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'created_at' => now(),
    ]);

    SmOrderItemFactory::new()->create([
        'order_id' => $affectedOrderOne->id,
        'product_id' => $productA->id,
    ]);

    $affectedOrderTwo = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'created_at' => now(),
    ]);

    SmOrderItemFactory::new()->create([
        'order_id' => $affectedOrderTwo->id,
        'product_id' => $productB->id,
    ]);

    SmOrderItemFactory::new()->create([
        'order_id' => $affectedOrderTwo->id,
        'product_id' => $productA->id,
    ]);

    $outsideWindowOrder = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'created_at' => now()->subDays(10),
    ]);

    SmOrderItemFactory::new()->create([
        'order_id' => $outsideWindowOrder->id,
        'product_id' => $productA->id,
    ]);

    $otherStoreOrder = SmOrderFactory::new()->create([
        'store_id' => $otherStore->id,
        'created_at' => now(),
    ]);

    SmOrderItemFactory::new()->create([
        'order_id' => $otherStoreOrder->id,
        'product_id' => $productA->id,
    ]);

    $nonOfferProductOrder = SmOrderFactory::new()->create([
        'store_id' => $this->store->id,
        'created_at' => now(),
    ]);

    SmOrderItemFactory::new()->create([
        'order_id' => $nonOfferProductOrder->id,
        'product_id' => $otherProduct->id,
    ]);

    $response = $this->getJson("/api/v1/sm-offers/{$offer->id}");

    $response->assertOk()
        ->assertJsonPath('data.offerProductsCount', 2)
        ->assertJsonPath('data.affectedOrdersCount', 2);
});
