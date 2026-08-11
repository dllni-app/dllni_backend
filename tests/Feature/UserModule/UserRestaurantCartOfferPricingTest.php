<?php

declare(strict_types=1);

use App\Models\User;
use Modules\Resturants\Enums\DiscountType;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Product;
use Modules\User\Services\UserRestaurantCartService;

it('applies an active restaurant product offer to cart prices', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'price' => 10,
        'discounted_price' => null,
    ]);

    $offer = Offer::create([
        'restaurant_id' => $product->restaurant_id,
        'name' => 'Weekly offer',
        'discount_type' => DiscountType::Percentage,
        'discount_value' => 15,
        'starts_at' => now()->subHour(),
        'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);
    $offer->products()->attach($product->id);

    $result = app(UserRestaurantCartService::class)->addItem(
        userId: (int) $user->id,
        productId: (int) $product->id,
        quantity: 1,
    );

    $cart = $result['cart'];
    expect($cart['items'][0]['unitPrice'])->toBe(8.5)
        ->and($cart['items'][0]['originalUnitPrice'])->toBe(10.0)
        ->and($cart['items'][0]['discountAmount'])->toBe(1.5)
        ->and($cart['amounts']['subtotal'])->toBe(10.0)
        ->and($cart['amounts']['discount'])->toBe(1.5)
        ->and($cart['amounts']['total'])->toBe(8.5);
});

it('refreshes existing cart item prices when an offer becomes active', function (): void {
    $user = User::factory()->create();
    $product = Product::factory()->create([
        'price' => 10,
        'discounted_price' => null,
    ]);

    $service = app(UserRestaurantCartService::class);
    $service->addItem(
        userId: (int) $user->id,
        productId: (int) $product->id,
        quantity: 1,
    );

    $offer = Offer::create([
        'restaurant_id' => $product->restaurant_id,
        'name' => 'Late offer',
        'discount_type' => DiscountType::FixedAmount,
        'discount_value' => 2,
        'starts_at' => now()->subMinute(),
        'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);
    $offer->products()->attach($product->id);

    $cart = $service->show((int) $user->id);

    expect($cart['items'][0]['unitPrice'])->toBe(8.0)
        ->and($cart['items'][0]['discountAmount'])->toBe(2.0)
        ->and($cart['amounts']['subtotal'])->toBe(10.0)
        ->and($cart['amounts']['discount'])->toBe(2.0)
        ->and($cart['amounts']['total'])->toBe(8.0);
});
