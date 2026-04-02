<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Favorite;
use Modules\Resturants\Models\Modifier;
use Modules\Resturants\Models\ModifierGroup;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

it('returns product details payload with modifier groups', function (): void {
    // Arrange
    $restaurant = Restaurant::factory()->create([
        'is_active' => true,
    ]);

    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
        'name' => 'Classic Burger',
        'price' => 32,
    ]);

    $group = ModifierGroup::create([
        'restaurant_id' => $restaurant->id,
        'name' => 'Optional Add-ons',
        'is_required' => false,
        'min_selections' => 0,
        'max_selections' => 3,
    ]);

    $group->products()->attach($product->id);

    Modifier::create([
        'modifier_group_id' => $group->id,
        'name' => 'Extra cheese',
        'price' => 3,
        'sort_order' => 1,
    ]);

    // Act
    $response = $this->getJson("/api/v1/user/products/{$product->id}");

    // Assert
    $response->assertOk()->assertJsonStructure([
        'product',
        'modifierGroups' => [
            [
                'id',
                'restaurantId',
                'name',
                'isRequired',
                'minSelections',
                'maxSelections',
                'modifiers' => [
                    [
                        'id',
                        'modifierGroupId',
                        'name',
                        'price',
                        'sortOrder',
                    ],
                ],
            ],
        ],
    ]);

    $response->assertJsonPath('product.isFavorite', false);
});

it('sets isFavorite true in product details when the product is in authenticated user favorites', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id,
        'is_available' => true,
    ]);

    Favorite::create([
        'user_id' => $user->id,
        'favorable_type' => Product::class,
        'favorable_id' => $product->id,
    ]);

    $this->getJson("/api/v1/user/products/{$product->id}")
        ->assertOk()
        ->assertJsonPath('product.isFavorite', true);
});
