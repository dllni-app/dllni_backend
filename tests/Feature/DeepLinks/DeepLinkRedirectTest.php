<?php

declare(strict_types=1);

use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use function Pest\Laravel\get;

it('redirects canonical restaurant links to configured landing url', function (): void {
    config()->set('deep_links.web_landing_url', 'https://app.dllni.com/open');

    Restaurant::factory()->create([
        'slug' => 'my-restaurant',
        'is_active' => true,
    ]);

    $response = get('/restaurant/my-restaurant?source=whatsapp&campaign=launch');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('https://app.dllni.com/open?');
    expect($response->headers->get('Location'))->toContain('deep_link=https%3A%2F%2Fapp.dllni.com%2Frestaurant%2Fmy-restaurant');
    expect($response->headers->get('Location'))->toContain('source=whatsapp');
    expect($response->headers->get('Location'))->toContain('campaign=launch');
});

it('redirects invalid canonical links to safe fallback page', function (): void {
    config()->set('deep_links.invalid_fallback_url', 'https://app.dllni.com/not-found');

    $response = get('/vote/999999');

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('https://app.dllni.com/not-found');
});

it('redirects canonical product links', function (): void {
    config()->set('deep_links.web_landing_url', 'https://app.dllni.com/open');

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);

    $response = get('/product/' . $product->id);

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('deep_link=https%3A%2F%2Fapp.dllni.com%2Fproduct%2F' . $product->id);
});
