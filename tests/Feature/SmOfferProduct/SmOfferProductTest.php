<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmOfferProductFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists offer products', function (): void {
    SmOfferProductFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-offer-products?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('shows an offer product', function (): void {
    $offerProduct = SmOfferProductFactory::new()->create();

    $response = $this->getJson("/api/v1/sm-offer-products/{$offerProduct->id}");

    $response->assertOk();
    expect($response->json('data.id'))->toBe($offerProduct->id);
});
