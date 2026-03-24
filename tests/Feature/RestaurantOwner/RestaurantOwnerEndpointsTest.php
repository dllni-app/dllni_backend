<?php

declare(strict_types=1);

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SystemAlertStatus;
use App\Enums\UserModuleType;
use App\Models\SystemAlert;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Offer;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\OrderItem;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\PromoCode;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Models\RestaurantRole;
use Modules\Resturants\Models\RestaurantStaff;
use Spatie\Permission\Models\Permission;

beforeEach(function () {
    $this->owner = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
        'phone' => '+963933000001',
    ]);
    $this->restaurant = Restaurant::factory()->create([
        'user_id' => $this->owner->id,
    ]);
    Sanctum::actingAs($this->owner);
});

it('rejects non restaurant seller for owner endpoints', function () {
    Sanctum::actingAs(User::factory()->create([
        'module_type' => UserModuleType::CleaningWorker->value,
    ]));

    $response = $this->getJson('/api/v1/restaurant-owner/dashboard/performance');

    $response->assertForbidden();
});

it('returns performance dashboard payload', function () {
    Order::factory()->count(2)->create([
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Completed->value,
        'total_amount' => 100,
        'created_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/restaurant-owner/dashboard/performance?range=today');

    $response->assertOk();
    $response->assertJsonStructure([
        'range' => ['key', 'from', 'to'],
        'summary' => ['totalOrders', 'totalRevenue', 'averageOrderValue', 'cancellationRatePercent'],
        'topProducts',
        'fulfillment',
        'offersImpact',
    ]);
});

it('adds order item and recalculates totals only for editable statuses', function () {
    $category = Category::factory()->create(['restaurant_id' => $this->restaurant->id]);
    $product = Product::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'category_id' => $category->id,
        'price' => 20,
    ]);

    $order = Order::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Pending->value,
        'subtotal' => 0,
        'tax_amount' => 2,
        'service_fee' => 1,
        'total_amount' => 3,
    ]);

    $response = $this->postJson("/api/v1/restaurant-owner/orders/{$order->id}/items", [
        'productId' => $product->id,
        'quantity' => 2,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.subtotal', 40);
    $response->assertJsonPath('data.totalAmount', 43);
    $response->assertJsonPath('data.canEditItems', true);

    $order->update(['status' => OrderStatus::Preparing->value]);
    $itemId = OrderItem::query()->where('order_id', $order->id)->firstOrFail()->id;
    $blocked = $this->patchJson("/api/v1/restaurant-owner/orders/{$order->id}/items/{$itemId}", [
        'quantity' => 3,
    ]);
    $blocked->assertUnprocessable();
});

it('updates product availability using sold out today mode', function () {
    $category = Category::factory()->create(['restaurant_id' => $this->restaurant->id]);
    $product = Product::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'category_id' => $category->id,
        'is_available' => true,
    ]);

    $response = $this->patchJson("/api/v1/restaurant-owner/products/{$product->id}/availability", [
        'mode' => 'sold_out_today',
        'note' => 'Only today',
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.isAvailable', false);
    $response->assertJsonPath('data.availabilityMode', 'sold_out_today');
});

it('creates or links employee and toggles status', function () {
    RestaurantRole::query()->create([
        'restaurant_id' => $this->restaurant->id,
        'name' => 'Cashier',
        'slug' => 'cashier',
    ]);
    $permission = Permission::query()->firstOrCreate([
        'name' => 'ro.menu',
        'guard_name' => 'web',
    ]);

    $createResponse = $this->postJson('/api/v1/restaurant-owner/employees', [
        'name' => 'Employee One',
        'email' => 'employee.one@example.com',
        'phone' => '+963944000111',
        'password' => 'password123',
        'isActive' => true,
        'permissionIds' => [$permission->id],
    ]);

    $createResponse->assertCreated();
    $createResponse->assertJsonPath('data.permissions.0.id', $permission->id);
    $createResponse->assertJsonPath('data.permissions.0.name', 'ro.menu');
    $employeeUser = User::query()->where('email', 'employee.one@example.com')->firstOrFail();
    expect(Hash::check('password123', $employeeUser->password))->toBeTrue();

    $toggleResponse = $this->patchJson("/api/v1/restaurant-owner/employees/{$employeeUser->id}", [
        'isActive' => false,
    ]);

    $toggleResponse->assertOk();
    $toggleResponse->assertJsonPath('data.isActive', false);
});

it('forbids updating employee from another restaurant', function () {
    $otherRestaurant = Restaurant::factory()->create();
    $otherUser = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
    ]);
    $otherStaff = RestaurantStaff::create([
        'restaurant_id' => $otherRestaurant->id,
        'user_id' => $otherUser->id,
        'restaurant_role_id' => null,
        'is_active' => true,
    ]);

    $this->patchJson("/api/v1/restaurant-owner/employees/{$otherUser->id}", [
        'isActive' => false,
    ])->assertForbidden();
});

it('stores profile image for employee', function () {
    $profileImage = UploadedFile::fake()->image('employee.jpg');

    $response = $this->post('/api/v1/restaurant-owner/employees', [
        'name' => 'Photo Employee',
        'email' => 'photo.emp@example.com',
        'password' => 'password123',
        'profileImage' => $profileImage,
    ], ['Accept' => 'application/json']);

    $response->assertCreated();
    expect($response->json('data.user.profileImageUrl'))->not->toBeNull();

    $employeeUser = User::query()->where('email', 'photo.emp@example.com')->firstOrFail();
    expect($employeeUser->getFirstMediaUrl('primary-image'))->not->toBe('');
});

it('updates employee password and profile image', function () {
    $employeeUser = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
        'email' => 'employee.patch@example.com',
    ]);

    $staff = RestaurantStaff::create([
        'restaurant_id' => $this->restaurant->id,
        'user_id' => $employeeUser->id,
        'restaurant_role_id' => null,
        'is_active' => true,
    ]);

    $newImage = UploadedFile::fake()->image('updated.jpg');

    $employeeId = $employeeUser->id;

    $patchResponse = $this->patch("/api/v1/restaurant-owner/employees/{$employeeId}", [
        'password' => 'newsecret99',
        'profileImage' => $newImage,
    ], ['Accept' => 'application/json']);

    $patchResponse->assertOk();
    $employeeUser->refresh();
    expect(Hash::check('newsecret99', $employeeUser->password))->toBeTrue();
    expect($employeeUser->getFirstMediaUrl('primary-image'))->not->toBe('');
    expect($patchResponse->json('data.user.profileImageUrl'))->not->toBeNull();
});

it('deletes employee using user id', function () {
    $employeeUser = User::factory()->create([
        'module_type' => UserModuleType::RestaurantSeller->value,
        'email' => 'employee.delete@example.com',
    ]);

    $staff = RestaurantStaff::create([
        'restaurant_id' => $this->restaurant->id,
        'user_id' => $employeeUser->id,
        'restaurant_role_id' => null,
        'is_active' => true,
    ]);

    $response = $this->deleteJson("/api/v1/restaurant-owner/employees/{$employeeUser->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('restaurant_staff', ['id' => $staff->id]);
});

it('returns unified notifications and marks them as read', function () {
    $order = Order::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Accepted->value,
    ]);

    $this->owner->notifications()->create([
        'id' => (string) Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\ExampleNotification',
        'data' => [
            'type' => 'new_order',
            'title' => 'Order received',
            'body' => 'New order received.',
        ],
        'read_at' => null,
    ]);

    $systemAlert = SystemAlert::query()->create([
        'booking_id' => $order->id,
        'booking_type' => Order::class,
        'alert_type' => AlertType::OverdueCompletion->value,
        'severity' => AlertSeverity::High->value,
        'status' => SystemAlertStatus::New->value,
        'payload' => ['order_number' => $order->order_number],
    ]);

    $feed = $this->getJson('/api/v1/restaurant-owner/notifications?tab=all');
    $feed->assertOk();
    expect($feed->json('data'))->toHaveCount(2);

    $systemId = 'system:'.$systemAlert->id;
    $this->patchJson('/api/v1/restaurant-owner/notifications/'.urlencode($systemId).'/read')
        ->assertNoContent();

    $this->patchJson('/api/v1/restaurant-owner/notifications/read-all')
        ->assertNoContent();
});

it('returns offers and coupons summary endpoints', function () {
    Offer::query()->create([
        'restaurant_id' => $this->restaurant->id,
        'name' => 'Offer A',
        'discount_type' => 'percentage',
        'discount_value' => 20,
        'is_active' => true,
    ]);
    PromoCode::query()->create([
        'restaurant_id' => $this->restaurant->id,
        'code' => 'SAVE20',
        'discount_type' => 'percentage',
        'discount_value' => 20,
        'usage_count' => 3,
        'is_active' => true,
    ]);

    $this->getJson('/api/v1/restaurant-owner/offers/summary')
        ->assertOk()
        ->assertJsonStructure(['summary' => ['activeCount', 'expiredCount', 'totalUsageOrders']]);

    $this->getJson('/api/v1/restaurant-owner/coupons/summary')
        ->assertOk()
        ->assertJsonStructure(['summary' => ['activeCount', 'expiredCount', 'totalUsageOrders']]);
});

it('validates custom range params for performance endpoint', function () {
    $this->getJson('/api/v1/restaurant-owner/dashboard/performance?range=custom')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['from', 'to']);
});

it('returns owner order wrapper with payment breakdown and canEdit false for preparing order', function () {
    $order = Order::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Preparing->value,
        'subtotal' => 80,
        'discount_amount' => 5,
        'service_fee' => 2,
        'total_amount' => 77,
    ]);

    $response = $this->getJson("/api/v1/restaurant-owner/orders/{$order->id}");

    $response->assertOk();
    $response->assertJsonPath('data.canEditItems', false);
    $response->assertJsonPath('data.paymentBreakdown.subtotal', 80);
    $response->assertJsonPath('data.paymentBreakdown.total', 77);
});

it('blocks cross-restaurant order access', function () {
    $otherRestaurant = Restaurant::factory()->create();
    $order = Order::factory()->create(['restaurant_id' => $otherRestaurant->id]);

    $this->getJson("/api/v1/restaurant-owner/orders/{$order->id}")
        ->assertForbidden();
});

it('removes order item and recalculates totals', function () {
    $category = Category::factory()->create(['restaurant_id' => $this->restaurant->id]);
    $product = Product::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'category_id' => $category->id,
        'price' => 15,
    ]);

    $order = Order::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Accepted->value,
        'subtotal' => 45,
        'tax_amount' => 2,
        'service_fee' => 3,
        'total_amount' => 50,
    ]);

    $item = OrderItem::query()->create([
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 3,
        'unit_price' => 15,
        'total_price' => 45,
    ]);

    $response = $this->deleteJson("/api/v1/restaurant-owner/orders/{$order->id}/items/{$item->id}");

    $response->assertOk();
    $response->assertJsonPath('data.subtotal', 0);
    $response->assertJsonPath('data.totalAmount', 5);
});

it('supports offers and coupons owner list filters', function () {
    Offer::query()->create([
        'restaurant_id' => $this->restaurant->id,
        'name' => 'Active Offer',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'is_active' => true,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
    ]);
    Offer::query()->create([
        'restaurant_id' => $this->restaurant->id,
        'name' => 'Expired Offer',
        'discount_type' => 'percentage',
        'discount_value' => 20,
        'is_active' => false,
        'starts_at' => now()->subDays(4),
        'ends_at' => now()->subDay(),
    ]);

    PromoCode::query()->create([
        'restaurant_id' => $this->restaurant->id,
        'code' => 'LIVE10',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'is_active' => true,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
    ]);
    PromoCode::query()->create([
        'restaurant_id' => $this->restaurant->id,
        'code' => 'OLD10',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'is_active' => false,
        'starts_at' => now()->subDays(5),
        'ends_at' => now()->subDay(),
    ]);

    $this->getJson('/api/v1/restaurant-owner/offers?status=active')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->getJson('/api/v1/restaurant-owner/coupons?status=expired')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});

it('creates offer through legacy resturant-owner path alias', function () {
    $response = $this->postJson('/api/v1/resturant-owner/offers', [
        'name' => 'Legacy Path Offer',
        'discountType' => 'percentage',
        'discountValue' => 15,
        'isActive' => true,
    ]);

    $response->assertCreated();
    $response->assertJsonPath('data.name', 'Legacy Path Offer');
    $this->assertDatabaseHas('offers', [
        'restaurant_id' => $this->restaurant->id,
        'name' => 'Legacy Path Offer',
    ]);
});

it('updates offer through legacy resturant-owner path alias', function () {
    $offer = Offer::query()->create([
        'restaurant_id' => $this->restaurant->id,
        'name' => 'Legacy Old Offer',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'is_active' => true,
    ]);

    $response = $this->patchJson("/api/v1/resturant-owner/offers/{$offer->id}", [
        'name' => 'Legacy Updated Offer',
        'discountType' => 'fixed_amount',
        'discountValue' => 25,
        'isActive' => false,
    ]);

    $response->assertOk();
    $response->assertJsonPath('data.name', 'Legacy Updated Offer');
    $response->assertJsonPath('data.discountType', 'fixed_amount');
    $response->assertJsonPath('data.discountValue', 25);
    $this->assertDatabaseHas('offers', [
        'id' => $offer->id,
        'name' => 'Legacy Updated Offer',
        'discount_type' => 'fixed_amount',
        'discount_value' => 25,
        'is_active' => false,
    ]);
});

it('deletes offer through legacy resturant-owner path alias', function () {
    $offer = Offer::query()->create([
        'restaurant_id' => $this->restaurant->id,
        'name' => 'Legacy Delete Offer',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'is_active' => true,
    ]);

    $response = $this->deleteJson("/api/v1/resturant-owner/offers/{$offer->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('offers', [
        'id' => $offer->id,
    ]);
});

it('filters unified notifications by tab and unread only', function () {
    $order = Order::factory()->create([
        'restaurant_id' => $this->restaurant->id,
        'status' => OrderStatus::Accepted->value,
    ]);

    $this->owner->notifications()->create([
        'id' => (string) Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\ExampleNotification',
        'data' => [
            'type' => 'new_order',
            'title' => 'Order notification',
            'body' => 'Order body.',
        ],
        'read_at' => now(),
    ]);

    $this->owner->notifications()->create([
        'id' => (string) Illuminate\Support\Str::uuid(),
        'type' => 'App\\Notifications\\ExampleNotification',
        'data' => [
            'type' => 'new_offer',
            'title' => 'Offer notification',
            'body' => 'Offer body.',
        ],
        'read_at' => null,
    ]);

    SystemAlert::query()->create([
        'booking_id' => $order->id,
        'booking_type' => Order::class,
        'alert_type' => AlertType::OverdueCompletion->value,
        'severity' => AlertSeverity::High->value,
        'status' => SystemAlertStatus::New->value,
        'payload' => [],
    ]);

    $offersUnread = $this->getJson('/api/v1/restaurant-owner/notifications?tab=offers&unreadOnly=1');
    $offersUnread->assertOk();
    $offersUnread->assertJsonCount(1, 'data');
    $offersUnread->assertJsonPath('data.0.category', 'offers');
    $offersUnread->assertJsonPath('data.0.isRead', false);
});

it('gets and updates current restaurant context with image', function () {
    // Arrange
    $restaurant = $this->restaurant;

    // Act + Assert (GET)
    $this->getJson('/api/v1/restaurant-owner/restaurant')
        ->assertOk()
        ->assertJsonPath('data.id', $restaurant->id)
        ->assertJsonStructure([
            'data' => [
                'id',
                'name',
                'slug',
                'primaryImage',
                'images',
            ],
        ]);

    // Act + Assert (PUT with image)
    $payload = [
        'userId' => $restaurant->user_id,
        'name' => 'Updated Restaurant Name',
        'slug' => $restaurant->slug,
        'primaryImage' => UploadedFile::fake()->image('logo.jpg', 256, 256),
    ];

    $updateResponse = $this->put('/api/v1/restaurant-owner/restaurant', $payload);
    $updateResponse->assertOk();

    expect((string) $updateResponse->json('data.primaryImage'))->not->toBeEmpty();
});
