<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\User\Models\UserAddress;

beforeEach(function (): void {
    $this->owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
        'phone' => '+963933000001',
    ]);

    $this->restaurant = Restaurant::factory()->create([
        'user_id' => $this->owner->id,
    ]);

    Sanctum::actingAs($this->owner);
});

it('returns customer contact, address, normalized items and amounts for owner order details', function (): void {
    $customer = User::factory()->create([
        'name' => 'مستخدم التطبيق',
        'email' => 'customer@example.com',
        'phone' => '+963944111222',
    ]);

    $address = UserAddress::factory()->create([
        'user_id' => $customer->id,
        'label' => 'المنزل',
        'mobile' => '+963944111222',
        'city' => 'دمشق',
        'neighborhood' => 'المزة',
        'street' => 'شارع رئيسي',
        'building' => '12',
        'floor' => '3',
        'directions' => 'قرب الحديقة',
        'latitude' => 33.51380000,
        'longitude' => 36.27650000,
        'is_default' => true,
    ]);

    $category = Category::factory()->create(['restaurant_id' => $this->restaurant->id]);
    $product = Product::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'category_id' => $category->id,
        'name' => 'تست برغر',
        'price' => 110,
    ]);

    $order = Order::factory()->create([
        'user_id' => $customer->id,
        'user_address_id' => $address->id,
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Accepted->value,
        'subtotal' => 110,
        'discount_amount' => 0,
        'tax_amount' => 0,
        'service_fee' => 3.5,
        'total_amount' => 113.5,
        'special_instructions' => 'اريد اشرب وماحد واي',
        'kitchen_notes' => 'لا يوجد ملاحظات للمطبخ',
    ]);

    OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 1,
        'unit_price' => 110,
        'total_price' => 110,
        'special_instructions' => 'بدون بصل',
    ]);

    $response = $this->getJson("/api/v1/restaurant-owner/orders/{$order->id}");

    $response->assertOk();
    $response->assertJsonPath('data.customer.phone', '+963944111222');
    $response->assertJsonPath('data.customerAddress.city', 'دمشق');
    $response->assertJsonPath('data.delivery.address.neighborhood', 'المزة');
    $response->assertJsonPath('data.items.0.name', 'تست برغر');
    $response->assertJsonPath('data.items.0.quantity', 1);
    $response->assertJsonPath('data.amounts.subtotal', 110);
    $response->assertJsonPath('data.amounts.serviceFee', 3.5);
    $response->assertJsonPath('data.amounts.total', 113.5);
    $response->assertJsonPath('data.paymentBreakdown.total', 113.5);
});
