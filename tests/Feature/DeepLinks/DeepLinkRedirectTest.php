<?php

declare(strict_types=1);

use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Supermarket\Models\SmStore;
use App\Models\User;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

it('redirects canonical restaurant links to configured landing url', function (): void {
    config()->set('deep_links.canonical_host', 'dllni.mustafafares.com');
    config()->set('deep_links.web_landing_url', 'https://dllni.mustafafares.com/open');

    Restaurant::factory()->create([
        'slug' => 'my-restaurant',
        'is_active' => true,
    ]);

    $response = get('/restaurant/my-restaurant?source=whatsapp&campaign=launch');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('https://dllni.mustafafares.com/open?');
    expect($response->headers->get('Location'))->toContain('deep_link=https%3A%2F%2Fdllni.mustafafares.com%2Frestaurant%2Fmy-restaurant');
    expect($response->headers->get('Location'))->toContain('source=whatsapp');
    expect($response->headers->get('Location'))->toContain('campaign=launch');
});

it('redirects invalid canonical links to safe fallback page', function (): void {
    config()->set('deep_links.invalid_fallback_url', 'https://dllni.mustafafares.com/not-found');

    $response = get('/vote/999999');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('https://dllni.mustafafares.com/not-found');
});

it('handles /open landing links and redirects to configured landing url', function (): void {
    config()->set('deep_links.web_landing_url', 'https://dllni.mustafafares.com/open-app');
    config()->set('deep_links.canonical_host', 'dllni.mustafafares.com');

    Restaurant::factory()->create([
        'slug' => 'open-restaurant',
        'is_active' => true,
    ]);

    $response = get('/open?deep_link=' . urlencode('https://dllni.mustafafares.com/restaurant/open-restaurant') . '&source=whatsapp');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('https://dllni.mustafafares.com/open-app?')
        ->and($response->headers->get('Location'))->toContain('deep_link=https%3A%2F%2Fdllni.mustafafares.com%2Frestaurant%2Fopen-restaurant')
        ->and($response->headers->get('Location'))->toContain('source=whatsapp');
});

it('redirects /open to invalid fallback when deep link cannot be resolved', function (): void {
    config()->set('deep_links.invalid_fallback_url', 'https://dllni.mustafafares.com/not-found');

    $response = get('/open?deep_link=' . urlencode('https://dllni.mustafafares.com/restaurant/unknown-slug'));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('https://dllni.mustafafares.com/not-found?reason=not_found');
});

it('redirects canonical product links', function (): void {
    config()->set('deep_links.canonical_host', 'dllni.mustafafares.com');
    config()->set('deep_links.web_landing_url', 'https://dllni.mustafafares.com/open');

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);

    $response = get('/product/' . $product->id);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('deep_link=https%3A%2F%2Fdllni.mustafafares.com%2Fproduct%2F' . $product->id);
});

it('redirects browser requests on API product links to canonical web link', function (): void {
    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);

    $response = get('/api/v1/user/products/' . $product->id);

    $response->assertRedirect('/product/' . $product->id);
});

it('keeps JSON response for API clients on product links', function (): void {
    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);

    $response = getJson('/api/v1/user/products/' . $product->id);
    $response->assertOk();
    expect((string) $response->headers->get('content-type'))->toContain('application/json');
});

it('redirects browser requests on API supermarket store links to canonical web link', function (): void {
    $owner = User::factory()->create();
    $store = SmStore::query()->create([
        'owner_user_id' => $owner->id,
        'name' => 'Browser Redirect Store',
        'slug' => 'browser-redirect-store',
        'is_active' => true,
        'suspension_until' => null,
    ]);

    $response = get('/api/v1/user/supermarket/stores/' . $store->id);

    $response->assertRedirect('/store/' . $store->id);
});
