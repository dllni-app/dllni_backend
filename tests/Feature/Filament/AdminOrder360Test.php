<?php

declare(strict_types=1);

use App\Filament\Resources\Orders\OrderResource;
use App\Filament\Resources\SmOrders\SmOrderResource;
use App\Models\User;
use Database\Factories\SmProductFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryDriver;
use Modules\Delivery\Models\DeliveryDriverLocation;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\OrderStatusLog;
use Modules\Resturants\Models\Product;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmOrderItem;
use Modules\Supermarket\Models\SmOrderStatusLog;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $role = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($role);
    $this->actingAs($admin);
});

it('renders restaurant order 360 with items timeline and linked delivery tracking', function (): void {
    $order = Order::factory()->create([
        'order_number' => 'REST-360-001',
        'status' => 'preparing',
        'estimated_preparation_minutes' => 30,
        'estimated_ready_at' => now()->addMinutes(20),
    ]);

    $product = Product::factory()->create([
        'restaurant_id' => $order->restaurant_id,
        'name' => 'Burger 360',
    ]);

    OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2,
        'unit_price' => 5000,
        'total_price' => 10000,
        'special_instructions' => 'No onions',
    ]);

    OrderStatusLog::query()->create([
        'order_id' => $order->id,
        'from_status' => 'accepted',
        'to_status' => 'preparing',
        'note' => 'Kitchen started',
    ]);

    $company = DeliveryCompany::factory()->create(['name' => 'Platform Delivery Co']);
    $driver = DeliveryDriver::factory()->create([
        'company_id' => $company->id,
        'first_name' => 'Driver 360',
        'last_seen_at' => now(),
    ]);

    DeliveryDriverLocation::query()->create([
        'driver_id' => $driver->id,
        'latitude' => 33.5138,
        'longitude' => 36.2765,
        'recorded_at' => now(),
    ]);

    DeliveryOrder::factory()->create([
        'company_id' => $company->id,
        'driver_id' => $driver->id,
        'source_type' => 'restaurant_order',
        'source_id' => $order->id,
        'order_number' => 'DEL-REST-360',
        'pickup_address' => 'Restaurant Pickup Point',
        'pickup_latitude' => 33.5140,
        'pickup_longitude' => 36.2770,
        'dropoff_address' => 'Restaurant Dropoff Point',
        'dropoff_latitude' => 33.5200,
        'dropoff_longitude' => 36.2900,
        'status' => 'accepted',
    ]);

    $this->get(OrderResource::getUrl('view', ['record' => $order], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('REST-360-001')
        ->assertSee('Burger 360')
        ->assertSee('No onions')
        ->assertSee('Kitchen started')
        ->assertSee('DEL-REST-360')
        ->assertSee('Platform Delivery Co')
        ->assertSee('Driver 360')
        ->assertSee('33.5138')
        ->assertSee('Restaurant Pickup Point')
        ->assertSee('Restaurant Dropoff Point')
        ->assertSee('السائق في الطريق إلى نقطة الاستلام')
        ->assertSee('المندوب متجه للاستلام');
});

it('renders supermarket order 360 with items timeline and linked delivery tracking', function (): void {
    $order = SmOrder::factory()->create([
        'order_number' => 'SM-360-001',
        'status' => 'ready_for_pickup',
        'estimated_preparation_minutes' => 25,
        'estimated_ready_at' => now(),
    ]);

    $product = SmProductFactory::new()->create([
        'store_id' => $order->store_id,
        'name' => 'Milk 360',
    ]);

    SmOrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'product_name' => 'Milk 360',
        'quantity' => 3,
        'unit_price' => 2500,
        'total_price' => 7500,
    ]);

    SmOrderStatusLog::query()->create([
        'order_id' => $order->id,
        'from_status' => 'preparing',
        'to_status' => 'ready_for_pickup',
        'notes' => 'Store marked ready',
    ]);

    $company = DeliveryCompany::factory()->create(['name' => 'SM Delivery Co']);
    $driver = DeliveryDriver::factory()->create([
        'company_id' => $company->id,
        'first_name' => 'SM Driver 360',
        'last_seen_at' => now(),
    ]);

    DeliveryDriverLocation::query()->create([
        'driver_id' => $driver->id,
        'latitude' => 33.5201,
        'longitude' => 36.2901,
        'recorded_at' => now(),
    ]);

    DeliveryOrder::factory()->create([
        'company_id' => $company->id,
        'driver_id' => $driver->id,
        'source_type' => 'supermarket_order',
        'source_id' => $order->id,
        'order_number' => 'DEL-SM-360',
        'pickup_address' => 'Supermarket Pickup Point',
        'pickup_latitude' => 33.5210,
        'pickup_longitude' => 36.2910,
        'dropoff_address' => 'Supermarket Dropoff Point',
        'dropoff_latitude' => 33.5300,
        'dropoff_longitude' => 36.3000,
        'status' => 'accepted',
    ]);

    $this->get(SmOrderResource::getUrl('view', ['record' => $order], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('SM-360-001')
        ->assertSee('Milk 360')
        ->assertSee('Store marked ready')
        ->assertSee('DEL-SM-360')
        ->assertSee('SM Delivery Co')
        ->assertSee('SM Driver 360')
        ->assertSee('33.5201')
        ->assertSee('Supermarket Pickup Point')
        ->assertSee('Supermarket Dropoff Point')
        ->assertSee('السائق في الطريق إلى نقطة الاستلام')
        ->assertSee('المندوب متجه للاستلام');
});
