<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\SmOfferFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Http\Resources\SmProductResource;
use Modules\Supermarket\Models\SmOfferProduct;
use Modules\Supermarket\Models\SmProduct;
use Modules\User\Services\HomepageService;

uses(RefreshDatabase::class);

it('applies active linked offer to displayed product price and excludes future offers from homepage', function (): void {
    $store = SmStoreFactory::new()->create([
        'is_active' => true, 'is_temporarily_closed' => false,
        'suspension_until' => null,
    ]);
    $product = SmProductFactory::new()->create([
        'store_id' => $store->id, 'price' => 100,
        'discounted_price' => null, 'stock_quantity' => 10,
    ]);
    $offer = SmOfferFactory::new()->create([
        'store_id' => $store->id, 'is_active' => true,
        'offer_type' => 'percent', 'discount_percent' => 25,
        'starts_at' => now()->subHour(), 'ends_at' => now()->addDay(),
    ]);
    SmOfferProduct::query()->create(['offer_id' => $offer->id, 'product_id' => $product->id]);

    $refreshed = SmProduct::query()->findOrFail($product->id);
    expect($refreshed->effectivePrice())->toBe(75);
    $payload = (new SmProductResource($refreshed))->toArray(Request::create('/'));
    expect((float) $payload['finalPrice'])->toBe(75.0);

    $future = SmOfferFactory::new()->create([
        'store_id' => $store->id, 'is_active' => true,
        'starts_at' => now()->addDay(), 'ends_at' => now()->addDays(4),
    ]);
    $result = app(HomepageService::class)->supermarketFeaturedOffers(Request::create('/?limit=20', 'GET', ['limit' => 20]));
    $offers = $result['offers']->resolve(Request::create('/'));
    $ids = collect($offers)->pluck('id')->all();
    expect($ids)->toContain($offer->id)->not->toContain($future->id);
});

it('preserves fractional modifier prices on store-owner options update', function (): void {
    $seller = User::factory()->create(['module_type' => UserModuleType::SupermarketSeller->value]);
    Sanctum::actingAs($seller);
    $store = SmStoreFactory::new()->create(['owner_user_id' => $seller->id]);
    $product = SmProductFactory::new()->create(['store_id' => $store->id]);
    $response = $this->putJson("/api/v1/store-owner/products/{$product->id}/options", [
        'options' => [[
            'name' => 'الحجم',
            'isRequired' => false,
            'minSelections' => 0,
            'maxSelections' => 1,
            'sortOrder' => 0,
            'isActive' => true,
            'modifiers' => [[
                'name' => 'إضافة',
                'price' => 2.5,
                'sortOrder' => 0,
                'isAvailable' => true,
            ]],
        ]],
    ]);
    $response->assertSuccessful();
    expect((float) $response->json('data.options.0.modifiers.0.price'))->toBe(2.5);
});
