<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Http\Resources\OrderResource;
use Modules\Resturants\Models\Cart;
use Modules\Resturants\Models\Modifier;
use Modules\Resturants\Models\ModifierGroup;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;

it('retains the purchased restaurant product and modifier names after catalogue edits', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $restaurant = Restaurant::factory()->create(['is_active' => true]);
    $product = Product::factory()->create([
        'restaurant_id' => $restaurant->id, 'name' => 'Original shawarma',
        'price' => 1000, 'discounted_price' => null, 'is_available' => true,
    ]);
    $group = ModifierGroup::query()->create([
        'restaurant_id' => $restaurant->id, 'name' => 'Extras',
        'is_required' => false, 'min_selections' => 0, 'max_selections' => 2,
    ]);
    $group->products()->attach($product->id);
    $modifier = Modifier::query()->create([
        'modifier_group_id' => $group->id, 'name' => 'Original cheese',
        'price' => 300, 'sort_order' => 1,
    ]);

    $this->postJson('/api/v1/user/restaurants/cart/items', [
        'productId' => $product->id, 'quantity' => 2, 'modifierIds' => [$modifier->id],
    ])->assertCreated();

    $cart = Cart::query()->where('user_id', $user->id)->firstOrFail();
    $response = $this->postJson('/api/v1/user/restaurants/carts/'.$cart->id.'/orders', [
        'fulfillmentType' => 'pickup', 'receiveMode' => 'immediate',
    ])->assertCreated();

    $order = Order::query()->findOrFail((int) $response->json('data.id'));
    $item = $order->orderItems()->firstOrFail();
    expect($item->product_name)->toBe('Original shawarma')
        ->and($item->modifier_snapshot)->toBe([[
            'id' => $modifier->id,
            'modifierGroupId' => $group->id,
            'groupName' => 'Extras',
            'name' => 'Original cheese',
            'price' => 300,
        ]]);

    $product->update(['name' => 'Changed menu title']);
    $modifier->update(['name' => 'Changed modifier title']);

    $view = OrderResource::make($order->fresh(['orderItems.product']))->resolve();
    expect($view['orderItems'][0]['productName'])->toBe('Original shawarma')
        ->and($view['orderItems'][0]['product']['name'])->toBe('Original shawarma')
        ->and($view['orderItems'][0]['modifierSnapshot'][0]['name'])->toBe('Original cheese');
});
