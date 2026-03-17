<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmOfferFactory;
use Database\Factories\SmOfferProductFactory;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmOrderItemFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists offers', function (): void {
    SmOfferFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-offers?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('creates an offer', function (): void {
    $store = SmStoreFactory::new()->create();

    $payload = [
        'storeId' => $store->id,
        'name' => 'Summer Sale',
        'offerType' => 'Discount',
        'discountPercent' => 20,
        'isActive' => true,
    ];

    $response = $this->postJson('/api/v1/sm-offers', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_offers', ['name' => 'Summer Sale']);
});

it('updates an offer', function (): void {
    $offer = SmOfferFactory::new()->create(['name' => 'Old Offer']);

    $payload = ['name' => 'New Offer'];

    $response = $this->putJson("/api/v1/sm-offers/{$offer->id}", $payload);

    $response->assertOk();
    $this->assertDatabaseHas('sm_offers', ['id' => $offer->id, 'name' => 'New Offer']);
});

it('deletes an offer', function (): void {
    $offer = SmOfferFactory::new()->create();

    $response = $this->deleteJson("/api/v1/sm-offers/{$offer->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_offers', ['id' => $offer->id]);
});

it('returns offer products and affected orders counts', function (): void {
    $store = SmStoreFactory::new()->create();
    $otherStore = SmStoreFactory::new()->create();
    $offer = SmOfferFactory::new()->create([
        'store_id' => $store->id,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
    ]);

    $productA = SmProductFactory::new()->create(['store_id' => $store->id]);
    $productB = SmProductFactory::new()->create(['store_id' => $store->id]);
    $otherProduct = SmProductFactory::new()->create(['store_id' => $store->id]);

    SmOfferProductFactory::new()->create([
        'offer_id' => $offer->id,
        'product_id' => $productA->id,
    ]);

    SmOfferProductFactory::new()->create([
        'offer_id' => $offer->id,
        'product_id' => $productB->id,
    ]);

    $affectedOrderOne = SmOrderFactory::new()->create([
        'store_id' => $store->id,
        'created_at' => now(),
    ]);

    SmOrderItemFactory::new()->create([
        'order_id' => $affectedOrderOne->id,
        'product_id' => $productA->id,
    ]);

    $affectedOrderTwo = SmOrderFactory::new()->create([
        'store_id' => $store->id,
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
        'store_id' => $store->id,
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
        'store_id' => $store->id,
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
