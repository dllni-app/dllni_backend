<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\CleaningFinancialSetting;
use App\Models\BookingReview;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\ServiceCategory;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningService;
use Modules\Cleaning\Models\ServicePricing;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    CancellationPolicy::query()->firstOrCreate(
        ['module' => 'cleaning', 'name' => 'Test Cleaning Cancellation'],
        [
            'description' => 'Test policy',
            'rules' => ['free_until_hours' => 24],
            'is_active' => true,
            'is_default' => true,
        ]
    );

    CleaningBillingPolicy::query()->firstOrCreate(
        ['name' => 'Test Cleaning Billing'],
        [
            'billing_mode' => CleaningBillingMode::FullBookedTime->value,
            'rules' => ['charge_full_booked_hours' => true],
            'is_active' => true,
            'is_default' => true,
        ]
    );
});

it('creates a cleaning order for authenticated user', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Al Aziziyah Street, Building 12',
            'location_name' => 'Home',
            'rooms' => 3,
            'bedrooms' => 2,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '09:00',
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'numberOfWorkers' => 2,
        'termsAccepted' => true,
    ]);

    $response->assertCreated()->assertJsonStructure([
        'order' => ['id', 'customerId', 'status', 'totalPrice'],
    ]);

    $orderId = (int) $response->json('order.id');

    expect(DB::table('cleaning_bookings')
        ->where('id', $orderId)
        ->where('customer_id', $user->id)
        ->where('status', CleaningBookingStatus::Pending->value)
        ->where('number_of_workers', 2)
        ->where('is_pricing_final', false)
        ->exists())->toBeTrue();

    expect($response->json('order.numberOfWorkers'))->toBe(2);
    expect((float) $response->json('order.basePrice'))->toBeGreaterThan(0);
    expect($response->json('order.travelDistanceKm'))->toBeNull();
    expect((float) $response->json('order.travelFee'))->toBe(0.0);
    expect((float) $response->json('order.adminMargin'))->toBe(0.0);
    expect((bool) $response->json('order.isPricingFinal'))->toBeFalse();
    expect((float) $response->json('order.totalPrice'))->toBeGreaterThan(0);
    expect((float) $response->json('order.estimatedSqm'))->toBeGreaterThan(0);
    expect((float) $response->json('order.totalHours'))->toBeGreaterThan(0);
});

it('rejects calculated cleaning values supplied by the client when creating an order', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-10 09:00:00'));

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Al Aziziyah Street, Building 12',
            'location_name' => 'Home',
            'rooms' => 3,
            'bedrooms' => 2,
            'bathrooms' => 1,
            'living_room_size' => 'small',
            'estimatedSqm' => 120,
        ],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '09:00',
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'estimatedSqm' => 120,
        'estimatedHours' => 3,
        'totalHours' => 3,
        'basePrice' => 1000,
        'travelFee' => 200,
        'addonsTotal' => 0,
        'totalPrice' => 1200,
        'termsAccepted' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'propertyDetails',
        'estimatedSqm',
        'estimatedHours',
        'totalHours',
        'basePrice',
        'travelFee',
        'addonsTotal',
        'totalPrice',
    ]);
});

it('lists only current user cleaning orders', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $otherUser = User::factory()->create();

    $mine = CleaningBooking::factory()->create(['customer_id' => $user->id]);
    CleaningBooking::factory()->create(['customer_id' => $otherUser->id]);

    $response = getJson('/api/v1/user/cleaning/orders?perPage=20');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect((int) $response->json('data.0.id'))->toBe($mine->id);
});

it('shows own cleaning order and returns not found for another user order', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $mine = CleaningBooking::factory()->create(['customer_id' => $user->id]);
    $other = CleaningBooking::factory()->create(['customer_id' => User::factory()->create()->id]);

    getJson("/api/v1/user/cleaning/orders/{$mine->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $mine->id);

    getJson("/api/v1/user/cleaning/orders/{$other->id}")
        ->assertNotFound();
});

it('updates a pending cleaning order schedule', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $order = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    $response = patchJson("/api/v1/user/cleaning/orders/{$order->id}", [
        'scheduledDate' => now()->addDays(2)->format('Y-m-d'),
        'scheduledTime' => '11:00',
        'numberOfWorkers' => 3,
        'propertyDetails' => [
            'address' => 'Updated address',
            'rooms' => 4,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'living_room_size' => 'large',
        ],
    ]);

    $response->assertOk()->assertJsonPath('order.scheduledTime', '11:00');
    $response->assertJsonPath('order.numberOfWorkers', 3);

    $order->refresh();
    expect((string) $order->scheduled_time)->toStartWith('11:00');
    expect($order->number_of_workers)->toBe(3);
    expect((float) $order->total_hours)->toBeGreaterThan(0);
    expect((float) $order->total_price)->toBeGreaterThan(0);
});

it('returns estimated size and time for cleaning order wizard', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = postJson('/api/v1/user/cleaning/orders/estimate-size', [
        'propertyType' => 'house',
        'propertyDetails' => [
            'rooms' => 3,
            'bedrooms' => 2,
            'bathrooms' => 1,
            'living_room_size' => 'medium',
        ],
    ]);

    $response->assertOk()->assertJsonStructure([
        'size' => ['estimatedSqm', 'sizeTier'],
        'estimation' => ['estimatedHours', 'estimatedMinutes'],
    ]);

    expect((float) $response->json('size.estimatedSqm'))->toBe(171.0);
    expect((string) $response->json('size.sizeTier'))->toBe('large');
    expect((float) $response->json('estimation.estimatedHours'))->toBe(5.5);
    expect((int) $response->json('estimation.estimatedMinutes'))->toBe(330);
});

it('rejects calculated cleaning values supplied by the client when updating an order', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-10 09:00:00'));

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $order = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    patchJson("/api/v1/user/cleaning/orders/{$order->id}", [
        'propertyDetails' => [
            'address' => 'Updated address',
            'rooms' => 4,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'living_room_size' => 'large',
            'estimatedSqm' => 180,
        ],
        'estimatedSqm' => 180,
        'estimatedHours' => 6,
        'totalHours' => 6,
        'basePrice' => 1500,
        'travelFee' => 200,
        'addonsTotal' => 0,
        'totalPrice' => 1700,
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'propertyDetails',
        'estimatedSqm',
        'estimatedHours',
        'totalHours',
        'basePrice',
        'travelFee',
        'addonsTotal',
        'totalPrice',
    ]);
});

it('returns previously worked cleaning workers for current user', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $worker = Worker::factory()->create();

    CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::Completed->value,
    ]);

    $response = getJson('/api/v1/user/cleaning/orders/previous-workers');

    $response->assertOk();
    expect($response->json('workers'))->toHaveCount(1);
    expect((int) $response->json('workers.0.workerId'))->toBe($worker->id);
});

it('returns estimated cleaning price from backend algorithm', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'addressLatitude' => 33.5,
        'addressLongitude' => 36.3,
    ]);

    $response->assertOk()->assertJsonStructure([
        'size' => ['estimatedSqm', 'estimatedHours', 'sizeTier'],
        'pricing' => ['basePrice', 'travelFee', 'addonsTotal', 'distanceKm', 'adminMargin', 'isPricingFinal', 'totalPrice', 'currency'],
        'algorithmVersion',
    ]);

    expect((float) $response->json('size.estimatedSqm'))->toBe(115.0);
    expect((float) $response->json('size.estimatedHours'))->toBe(4.0);
    expect((string) $response->json('size.sizeTier'))->toBe('medium');
    expect((float) $response->json('pricing.basePrice'))->toBe(920.0);
    expect($response->json('pricing.distanceKm'))->toBeNull();
    expect((float) $response->json('pricing.travelFee'))->toBe(0.0);
    expect((float) $response->json('pricing.addonsTotal'))->toBe(0.0);
    expect((float) $response->json('pricing.adminMargin'))->toBe(0.0);
    expect((bool) $response->json('pricing.isPricingFinal'))->toBeFalse();
    expect((float) $response->json('pricing.totalPrice'))->toBe(920.0);
    expect((string) $response->json('algorithmVersion'))->toBe('2026-05-25-v2');
});

it('prefers room_size_breakdown for estimate-price when provided', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'rooms' => 1,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
            'room_size_breakdown' => [
                'bedroom' => ['small' => 1, 'medium' => 2, 'large' => 1],
                'bathroom' => ['small' => 1, 'medium' => 1, 'large' => 1],
                'kitchen' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'living_room' => ['small' => 0, 'medium' => 1, 'large' => 0],
            ],
        ],
    ]);

    $response->assertOk();
    expect((float) $response->json('size.estimatedSqm'))->toBe(331.0);
    expect((float) $response->json('size.estimatedHours'))->toBe(12.5);
    expect((string) $response->json('size.sizeTier'))->toBe('very_large');
    expect((float) $response->json('pricing.basePrice'))->toBe(2648.0);
});

it('creates order with room_size_breakdown and persists normalized breakdown-derived values', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Damascus - Kafar Souseh',
            'location_name' => 'Home',
            'rooms' => 1,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
            'room_size_breakdown' => [
                'bedroom' => ['small' => 1, 'medium' => 2, 'large' => 1],
                'bathroom' => ['small' => 1, 'medium' => 1, 'large' => 1],
                'kitchen' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'living_room' => ['small' => 0, 'medium' => 1, 'large' => 0],
            ],
        ],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '10:00',
        'termsAccepted' => true,
    ]);

    $response->assertCreated();
    expect($response->json('order.propertyDetails.bedrooms'))->toBe(9);
    expect($response->json('order.propertyDetails.rooms'))->toBe(4);
    expect($response->json('order.propertyDetails.bathrooms'))->toBe(3);
    expect($response->json('order.propertyDetails.kitchens'))->toBe(1);
    expect($response->json('order.propertyDetails.living_room_size'))->toBe('medium');
    expect($response->json('order.propertyDetails.room_size_breakdown.bedroom.large'))->toBe(1);
});

it('validates room_size_breakdown shape and rejects invalid bucket keys', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'room_size_breakdown' => [
                'bedroom' => ['tiny' => 1, 'medium' => 1, 'large' => 0],
                'bathroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'kitchen' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'living_room' => ['small' => 1, 'medium' => 0, 'large' => 0],
            ],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'propertyDetails.room_size_breakdown.bedroom',
        'propertyDetails.room_size_breakdown.bedroom.small',
    ]);
});

it('returns regular cleaning estimate with selected cleaning services in addons', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $serviceA = CleaningService::query()->create([
        'name' => 'Deep clean add-on',
        'slug' => 'deep-clean-addon-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::Cleaning->value,
        'description' => 'Deep cleaning',
        'is_active' => true,
    ]);
    $serviceB = CleaningService::query()->create([
        'name' => 'Kitchen add-on',
        'slug' => 'kitchen-clean-addon-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::Cleaning->value,
        'description' => 'Kitchen focused cleaning',
        'is_active' => true,
    ]);

    ServicePricing::query()->create([
        'cleaning_service_id' => $serviceA->id,
        'property_type' => 'apartment',
        'living_room_size' => 'small',
        'base_price' => 100,
        'price_per_sqm' => null,
        'min_hours' => 1,
    ]);
    ServicePricing::query()->create([
        'cleaning_service_id' => $serviceB->id,
        'property_type' => 'apartment',
        'living_room_size' => 'small',
        'base_price' => 80,
        'price_per_sqm' => null,
        'min_hours' => 1,
    ]);

    $response = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'serviceIds' => [$serviceA->id, $serviceB->id],
    ]);

    $response->assertOk();
    expect((float) $response->json('pricing.basePrice'))->toBe(920.0);
    expect((float) $response->json('pricing.addonsTotal'))->toBe(180.0);
    expect((float) $response->json('pricing.totalPrice'))->toBe(1100.0);
    expect($response->json('pricing.serviceLines'))->toHaveCount(2);
});

it('returns finalized pricing when preferred worker is selected in estimate endpoint', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 10,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'travel_per_km' => 10,
            'travel_distance_start_point' => 'worker_home',
        ]
    );

    $worker = Worker::factory()->create([
        'home_address' => 'Worker Home',
        'home_latitude' => 33.6,
        'home_longitude' => 36.3,
    ]);

    $response = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'addressLatitude' => 33.5,
        'addressLongitude' => 36.3,
        'preferredWorkerId' => $worker->id,
    ]);

    $response->assertOk();
    expect((bool) $response->json('pricing.isPricingFinal'))->toBeTrue();
    expect((float) $response->json('pricing.distanceKm'))->toBe(11.119);
    expect((float) $response->json('pricing.travelFee'))->toBe(111.19);
    expect((float) $response->json('pricing.adminMargin'))->toBe(103.12);
    expect((float) $response->json('pricing.totalPrice'))->toBe(1134.31);
});

it('creates a cleaning order with totals matching a prior estimate for the same inputs', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $pricePayload = [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'addressLatitude' => 33.5,
        'addressLongitude' => 36.3,
    ];

    $priceResponse = postJson('/api/v1/user/cleaning/orders/estimate-price', $pricePayload)
        ->assertOk();

    $expectedBasePrice = (float) $priceResponse->json('pricing.basePrice');
    $expectedTravelFee = (float) $priceResponse->json('pricing.travelFee');
    $expectedAddonsTotal = (float) $priceResponse->json('pricing.addonsTotal');
    $expectedTotalPrice = (float) $priceResponse->json('pricing.totalPrice');
    $expectedEstimatedSqm = (float) $priceResponse->json('size.estimatedSqm');
    $expectedEstimatedHours = (float) $priceResponse->json('size.estimatedHours');

    $createResponse = postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Al Aziziyah Street, Building 12',
            'location_name' => 'Home',
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '09:00',
        'addressLatitude' => 33.5,
        'addressLongitude' => 36.3,
        'termsAccepted' => true,
    ]);

    $createResponse->assertCreated();
    expect((float) $createResponse->json('order.basePrice'))->toBe($expectedBasePrice);
    expect((float) $createResponse->json('order.travelFee'))->toBe($expectedTravelFee);
    expect((float) $createResponse->json('order.addonsTotal'))->toBe($expectedAddonsTotal);
    expect((float) $createResponse->json('order.totalPrice'))->toBe($expectedTotalPrice);
    expect((float) $createResponse->json('order.estimatedSqm'))->toBe($expectedEstimatedSqm);
    expect((float) $createResponse->json('order.estimatedHours'))->toBe($expectedEstimatedHours);
});

it('creates regular cleaning order with selected services and persists booking service lines', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $serviceA = CleaningService::query()->create([
        'name' => 'Balcony add-on',
        'slug' => 'balcony-addon-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::Cleaning->value,
        'description' => 'Balcony cleaning',
        'is_active' => true,
    ]);
    $serviceB = CleaningService::query()->create([
        'name' => 'Window add-on',
        'slug' => 'window-addon-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::Cleaning->value,
        'description' => 'Window cleaning',
        'is_active' => true,
    ]);

    ServicePricing::query()->create([
        'cleaning_service_id' => $serviceA->id,
        'property_type' => 'apartment',
        'living_room_size' => 'small',
        'base_price' => 120,
        'price_per_sqm' => null,
        'min_hours' => 1,
    ]);
    ServicePricing::query()->create([
        'cleaning_service_id' => $serviceB->id,
        'property_type' => 'apartment',
        'living_room_size' => 'small',
        'base_price' => 90,
        'price_per_sqm' => null,
        'min_hours' => 1,
    ]);

    $response = postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Damascus - Mazzeh',
            'location_name' => 'Home',
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'serviceIds' => [$serviceA->id, $serviceB->id],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '10:30',
        'termsAccepted' => true,
    ]);

    $response->assertCreated();
    $orderId = (int) $response->json('order.id');

    expect((float) $response->json('order.addonsTotal'))->toBe(210.0);
    expect((float) $response->json('order.totalPrice'))->toBe(1130.0);

    $this->assertDatabaseHas('cleaning_booking_service', [
        'cleaning_booking_id' => $orderId,
        'cleaning_service_id' => $serviceA->id,
        'unit_price' => 120.0,
        'total_price' => 120.0,
    ]);
    $this->assertDatabaseHas('cleaning_booking_service', [
        'cleaning_booking_id' => $orderId,
        'cleaning_service_id' => $serviceB->id,
        'unit_price' => 90.0,
        'total_price' => 90.0,
    ]);
});

it('allows schedule-only update on a pending cleaning order', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-23 09:00:00'));

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $order = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    patchJson("/api/v1/user/cleaning/orders/{$order->id}", [
        'scheduledDate' => now()->addDays(2)->format('Y-m-d'),
        'scheduledTime' => '12:30',
    ])->assertOk()->assertJsonPath('order.scheduledTime', '12:30');
});

it('recalculates totals on price-affecting update', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-23 09:00:00'));

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $order = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    $priceResponse = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => (string) $order->property_type,
        'propertyDetails' => [
            'address' => 'Updated address',
            'rooms' => 4,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'living_room_size' => 'large',
        ],
    ])->assertOk();

    $expectedTotalPrice = (float) $priceResponse->json('pricing.totalPrice');

    patchJson("/api/v1/user/cleaning/orders/{$order->id}", [
        'propertyDetails' => [
            'address' => 'Updated address',
            'rooms' => 4,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'living_room_size' => 'large',
        ],
    ])->assertOk();

    $order->refresh();
    expect((float) $order->total_price)->toBe($expectedTotalPrice);
});

it('updates property type and recalculates totals from server pricing', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-23 09:00:00'));

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $order = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::Pending->value,
        'property_type' => 'apartment',
        'property_details' => [
            'address' => 'Old address',
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'address_latitude' => 33.5,
        'address_longitude' => 36.3,
    ]);

    $priceResponse = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'villa',
        'propertyDetails' => [
            'rooms' => 4,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'living_room_size' => 'large',
        ],
        'addressLatitude' => 33.6,
        'addressLongitude' => 36.4,
    ])->assertOk();

    $expectedTotalPrice = (float) $priceResponse->json('pricing.totalPrice');

    patchJson("/api/v1/user/cleaning/orders/{$order->id}", [
        'propertyType' => 'villa',
        'propertyDetails' => [
            'address' => 'Updated address',
            'rooms' => 4,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'living_room_size' => 'large',
        ],
        'addressLatitude' => 33.6,
        'addressLongitude' => 36.4,
    ])->assertOk()->assertJsonPath('order.propertyType', 'villa');

    $order->refresh();
    expect((string) $order->property_type)->toBe('villa');
    expect((float) $order->total_price)->toBe($expectedTotalPrice);
});

it('updates regular cleaning order services and re-syncs booking service lines', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $serviceA = CleaningService::query()->create([
        'name' => 'Regular service A',
        'slug' => 'regular-service-a-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::Cleaning->value,
        'description' => 'A',
        'is_active' => true,
    ]);
    $serviceB = CleaningService::query()->create([
        'name' => 'Regular service B',
        'slug' => 'regular-service-b-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::Cleaning->value,
        'description' => 'B',
        'is_active' => true,
    ]);
    $serviceC = CleaningService::query()->create([
        'name' => 'Regular service C',
        'slug' => 'regular-service-c-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::Cleaning->value,
        'description' => 'C',
        'is_active' => true,
    ]);

    ServicePricing::query()->create([
        'cleaning_service_id' => $serviceA->id,
        'property_type' => 'apartment',
        'living_room_size' => 'small',
        'base_price' => 100,
        'price_per_sqm' => null,
        'min_hours' => 1,
    ]);
    ServicePricing::query()->create([
        'cleaning_service_id' => $serviceB->id,
        'property_type' => 'apartment',
        'living_room_size' => 'small',
        'base_price' => 70,
        'price_per_sqm' => null,
        'min_hours' => 1,
    ]);
    ServicePricing::query()->create([
        'cleaning_service_id' => $serviceC->id,
        'property_type' => 'apartment',
        'living_room_size' => 'small',
        'base_price' => 40,
        'price_per_sqm' => null,
        'min_hours' => 1,
    ]);

    $create = postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Damascus',
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'serviceIds' => [$serviceA->id],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '16:00',
        'termsAccepted' => true,
    ])->assertCreated();

    $orderId = (int) $create->json('order.id');

    patchJson("/api/v1/user/cleaning/orders/{$orderId}", [
        'serviceIds' => [$serviceB->id, $serviceC->id],
        'propertyDetails' => [
            'address' => 'Damascus updated',
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
    ])->assertOk();

    $this->assertDatabaseHas('cleaning_booking_service', [
        'cleaning_booking_id' => $orderId,
        'cleaning_service_id' => $serviceB->id,
        'unit_price' => 70.0,
    ]);
    $this->assertDatabaseHas('cleaning_booking_service', [
        'cleaning_booking_id' => $orderId,
        'cleaning_service_id' => $serviceC->id,
        'unit_price' => 40.0,
    ]);
    $this->assertDatabaseMissing('cleaning_booking_service', [
        'cleaning_booking_id' => $orderId,
        'cleaning_service_id' => $serviceA->id,
    ]);
});

it('cancels pending cleaning order and rejects cancelling completed order', function (): void {
    Event::fake([CleaningBookingTrackingUpdated::class]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $pendingOrder = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    postJson("/api/v1/user/cleaning/orders/{$pendingOrder->id}/cancel", [
        'reason' => 'Changed plans',
    ])->assertOk()->assertJsonPath('order.status', CleaningBookingStatus::Cancelled->value);

    Event::assertDispatched(CleaningBookingTrackingUpdated::class, function (CleaningBookingTrackingUpdated $event) use ($pendingOrder): bool {
        return $event->cleaningBookingId === $pendingOrder->id
            && $event->tracking['status'] === CleaningBookingStatus::Cancelled->value
            && $event->tracking['cancelledAt'] !== null;
    });

    $completedOrder = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::Completed->value,
    ]);

    postJson("/api/v1/user/cleaning/orders/{$completedOrder->id}/cancel", [
        'reason' => 'Too late',
    ])->assertUnprocessable();
});

it('submits cleaning order review for completed order', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $order = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::Completed->value,
    ]);

    postJson("/api/v1/user/cleaning/orders/{$order->id}/review", [
        'rating' => 5,
        'comment' => 'Great service and on-time delivery.',
    ])->assertOk()
        ->assertJsonPath('data.ok', true);

    expect(BookingReview::query()
        ->where('booking_id', $order->id)
        ->where('booking_type', $order->getMorphClass())
        ->where('customer_id', $user->id)
        ->where('rating', 5)
        ->exists())->toBeTrue();
});

it('rejects cleaning order review for non-completed order', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $order = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::InProgress->value,
    ]);

    postJson("/api/v1/user/cleaning/orders/{$order->id}/review", [
        'rating' => 4,
        'comment' => 'Good work.',
    ])->assertUnprocessable()
        ->assertJsonValidationErrors(['status']);
});

it('estimates event assistance pricing with recommendation and selected services', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $setupEventServices = function (): array {
        $serviceA = CleaningService::query()->create([
            'name' => 'Event serving support',
            'slug' => 'event-serving-support-'.fake()->unique()->numerify('###'),
            'category' => ServiceCategory::EventAssistance->value,
            'description' => 'Serving support for events',
            'is_active' => true,
        ]);
        $serviceB = CleaningService::query()->create([
            'name' => 'Event cleanup support',
            'slug' => 'event-cleanup-support-'.fake()->unique()->numerify('###'),
            'category' => ServiceCategory::EventAssistance->value,
            'description' => 'Cleanup support for events',
            'is_active' => true,
        ]);

        ServicePricing::query()->create([
            'cleaning_service_id' => $serviceA->id,
            'property_type' => 'apartment',
            'living_room_size' => null,
            'base_price' => 300,
            'price_per_sqm' => null,
            'min_hours' => 3,
        ]);
        // Purposefully no apartment row: price should fall back to first row for this service.
        ServicePricing::query()->create([
            'cleaning_service_id' => $serviceB->id,
            'property_type' => 'villa',
            'living_room_size' => null,
            'base_price' => 250,
            'price_per_sqm' => null,
            'min_hours' => 2,
        ]);

        return [$serviceA, $serviceB];
    };

    [$serviceA, $serviceB] = $setupEventServices();

    $response = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'eventType' => 'birthday',
            'guestCount' => 45,
            'venueType' => 'apartment',
        ],
        'serviceIds' => [$serviceA->id, $serviceB->id],
    ]);

    $response->assertOk();
    expect((float) $response->json('pricing.basePrice'))->toBe(550.0);
    expect((float) $response->json('pricing.totalPrice'))->toBe(550.0);
    expect((float) $response->json('size.estimatedHours'))->toBe(5.0);
    expect($response->json('recommendation.guestCount'))->toBe(45);
    expect($response->json('recommendation.suggestedTeamSize'))->toBe(6);
    expect($response->json('pricing.serviceLines'))->toHaveCount(2);
});

it('creates event assistance order and syncs booking services pivot', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $serviceA = CleaningService::query()->create([
        'name' => 'Event setup support',
        'slug' => 'event-setup-support-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::EventAssistance->value,
        'description' => 'Setup support for events',
        'is_active' => true,
    ]);
    $serviceB = CleaningService::query()->create([
        'name' => 'Event hosting support',
        'slug' => 'event-hosting-support-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::EventAssistance->value,
        'description' => 'Hosting support for events',
        'is_active' => true,
    ]);

    ServicePricing::query()->create([
        'cleaning_service_id' => $serviceA->id,
        'property_type' => 'apartment',
        'living_room_size' => null,
        'base_price' => 300,
        'price_per_sqm' => null,
        'min_hours' => 3,
    ]);
    ServicePricing::query()->create([
        'cleaning_service_id' => $serviceB->id,
        'property_type' => 'apartment',
        'living_room_size' => null,
        'base_price' => 250,
        'price_per_sqm' => null,
        'min_hours' => 2,
    ]);

    $response = postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'address' => 'Damascus, Mazzeh',
            'location_name' => 'Family Hall',
            'eventType' => 'family_dinner',
            'guestCount' => 40,
            'venueType' => 'apartment',
            'specialRequirement' => 'Male helpers only',
            'notes' => 'Call before arrival',
        ],
        'serviceIds' => [$serviceA->id, $serviceB->id],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '18:30',
        'genderPreference' => 'male',
        'termsAccepted' => true,
    ]);

    $response->assertCreated();
    $orderId = (int) $response->json('order.id');

    expect($response->json('order.propertyType'))->toBe('event_assistance');
    expect($response->json('order.genderPreference'))->toBe('male');
    // Suggested team size = ceil(40/10) + (2-1) = 5
    expect($response->json('order.numberOfWorkers'))->toBe(5);
    expect((float) $response->json('order.basePrice'))->toBe(550.0);

    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $orderId,
        'property_type' => 'event_assistance',
        'gender_preference' => 'male',
    ]);
    $this->assertDatabaseHas('cleaning_booking_service', [
        'cleaning_booking_id' => $orderId,
        'cleaning_service_id' => $serviceA->id,
        'unit_price' => 300.0,
        'total_price' => 300.0,
    ]);
    $this->assertDatabaseHas('cleaning_booking_service', [
        'cleaning_booking_id' => $orderId,
        'cleaning_service_id' => $serviceB->id,
        'unit_price' => 250.0,
        'total_price' => 250.0,
    ]);
});

it('updates event assistance order and re-syncs selected services', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $serviceA = CleaningService::query()->create([
        'name' => 'Event prep support',
        'slug' => 'event-prep-support-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::EventAssistance->value,
        'description' => 'Preparation support',
        'is_active' => true,
    ]);
    $serviceB = CleaningService::query()->create([
        'name' => 'Event cleanup premium',
        'slug' => 'event-cleanup-premium-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::EventAssistance->value,
        'description' => 'Premium cleanup support',
        'is_active' => true,
    ]);
    $serviceC = CleaningService::query()->create([
        'name' => 'Event serving premium',
        'slug' => 'event-serving-premium-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::EventAssistance->value,
        'description' => 'Premium serving support',
        'is_active' => true,
    ]);

    ServicePricing::query()->create([
        'cleaning_service_id' => $serviceA->id,
        'property_type' => 'apartment',
        'living_room_size' => null,
        'base_price' => 200,
        'price_per_sqm' => null,
        'min_hours' => 2,
    ]);
    ServicePricing::query()->create([
        'cleaning_service_id' => $serviceB->id,
        'property_type' => 'apartment',
        'living_room_size' => null,
        'base_price' => 350,
        'price_per_sqm' => null,
        'min_hours' => 3,
    ]);
    ServicePricing::query()->create([
        'cleaning_service_id' => $serviceC->id,
        'property_type' => 'apartment',
        'living_room_size' => null,
        'base_price' => 150,
        'price_per_sqm' => null,
        'min_hours' => 2,
    ]);

    $create = postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'address' => 'Damascus',
            'eventType' => 'birthday',
            'guestCount' => 25,
            'venueType' => 'apartment',
        ],
        'serviceIds' => [$serviceA->id],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '16:00',
        'termsAccepted' => true,
    ])->assertCreated();

    $orderId = (int) $create->json('order.id');

    $update = patchJson("/api/v1/user/cleaning/orders/{$orderId}", [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'address' => 'Damascus - updated',
            'eventType' => 'large_gathering',
            'guestCount' => 60,
            'venueType' => 'apartment',
            'notes' => 'Need early arrival',
        ],
        'serviceIds' => [$serviceB->id, $serviceC->id],
    ]);

    $update->assertOk();
    expect((float) $update->json('order.basePrice'))->toBe(500.0);
    expect($update->json('order.propertyDetails.event_type'))->toBe('large_gathering');
    expect($update->json('order.propertyDetails.guest_count'))->toBe(60);

    $this->assertDatabaseHas('cleaning_booking_service', [
        'cleaning_booking_id' => $orderId,
        'cleaning_service_id' => $serviceB->id,
        'unit_price' => 350.0,
    ]);
    $this->assertDatabaseHas('cleaning_booking_service', [
        'cleaning_booking_id' => $orderId,
        'cleaning_service_id' => $serviceC->id,
        'unit_price' => 150.0,
    ]);
    $this->assertDatabaseMissing('cleaning_booking_service', [
        'cleaning_booking_id' => $orderId,
        'cleaning_service_id' => $serviceA->id,
    ]);
});

it('validates required event assistance fields and rejects non-event services', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'guestCount' => 10,
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'propertyDetails.eventType',
        'propertyDetails.venueType',
        'serviceIds',
    ]);

    $nonEventService = CleaningService::query()->create([
        'name' => 'Normal cleaning service',
        'slug' => 'normal-cleaning-service-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::Cleaning->value,
        'description' => 'Normal service',
        'is_active' => true,
    ]);
    ServicePricing::query()->create([
        'cleaning_service_id' => $nonEventService->id,
        'property_type' => 'apartment',
        'living_room_size' => null,
        'base_price' => 100,
        'price_per_sqm' => null,
        'min_hours' => 1,
    ]);

    postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'eventType' => 'birthday',
            'guestCount' => 20,
            'venueType' => 'apartment',
        ],
        'serviceIds' => [$nonEventService->id],
    ])->assertUnprocessable()->assertJsonValidationErrors(['pricing']);
});
