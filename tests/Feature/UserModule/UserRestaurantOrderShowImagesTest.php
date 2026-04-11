<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

it('requires authentication to show a restaurant order', function (): void {
    $response = $this->getJson('/api/v1/user/orders/restaurant/1');

    $response->assertUnauthorized();
});

it('includes merchant and line item image urls on restaurant order detail', function (): void {
    Storage::fake('public');

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $restaurant->addMedia(UploadedFile::fake()->image('shop.jpg'))
        ->toMediaCollection('primary-image');
    $restaurant->addMedia(UploadedFile::fake()->image('banner.jpg'))
        ->toMediaCollection('banner-image');

    $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'name' => 'Platter',
    ]);
    $product->addMedia(UploadedFile::fake()->image('dish-main.jpg'))
        ->toMediaCollection('primary-image');
    $product->addMedia(UploadedFile::fake()->image('dish-1.jpg'))
        ->toMediaCollection('images');

    $order = Order::factory()->create([
        'user_id' => $user->id,
        'restaurant_id' => $restaurant->id,
        'status' => OrderStatus::Completed->value,
    ]);

    OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'substitute_product_id' => null,
        'quantity' => 1,
        'unit_price' => 10,
        'total_price' => 10,
        'special_instructions' => null,
    ]);

    $response = $this->getJson("/api/v1/user/orders/restaurant/{$order->id}");

    $response->assertOk();
    $response->assertJsonPath('data.merchant.id', $restaurant->id);
    expect($response->json('data.merchant.primaryImageUrl'))->toBeString()->not->toBeEmpty();
    expect($response->json('data.merchant.bannerImageUrl'))->toBeString()->not->toBeEmpty();

    expect($response->json('data.items.0.primaryImageUrl'))->toBeString()->not->toBeEmpty();
    expect($response->json('data.items.0.images'))->toBeArray()->not->toBeEmpty();
    $response->assertJsonPath('data.items.0.name', 'Platter');
});
