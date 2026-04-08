<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;

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
        ->exists())->toBeTrue();

    expect((float) $response->json('order.basePrice'))->toBeGreaterThan(0);
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
        'propertyDetails' => [
            'address' => 'Updated address',
            'rooms' => 4,
            'bedrooms' => 3,
            'bathrooms' => 2,
            'living_room_size' => 'large',
        ],
    ]);

    $response->assertOk()->assertJsonPath('order.scheduledTime', '11:00');

    $order->refresh();
    expect((string) $order->scheduled_time)->toStartWith('11:00');
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
        'pricing' => ['basePrice', 'travelFee', 'addonsTotal', 'totalPrice', 'currency'],
        'quote' => ['quoteId', 'expiresAt', 'algorithmVersion'],
    ]);

    expect((float) $response->json('size.estimatedSqm'))->toBe(115.0);
    expect((float) $response->json('size.estimatedHours'))->toBe(4.0);
    expect((string) $response->json('size.sizeTier'))->toBe('medium');
    expect((float) $response->json('pricing.basePrice'))->toBe(920.0);
    expect((float) $response->json('pricing.travelFee'))->toBe(150.0);
    expect((float) $response->json('pricing.addonsTotal'))->toBe(0.0);
    expect((float) $response->json('pricing.totalPrice'))->toBe(1070.0);
    expect((string) $response->json('quote.quoteId'))->toStartWith('clnq_');
    expect((string) $response->json('quote.algorithmVersion'))->toBe('2026-04-08-v1');
});

it('creates a cleaning order using a valid quote and persists quote-owned totals', function (): void {
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

    $quoteId = (string) $priceResponse->json('quote.quoteId');
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
        'quoteId' => $quoteId,
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

it('allows creating a cleaning order without quote during grace period', function (): void {
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
        ],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '09:00',
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'termsAccepted' => true,
    ])->assertCreated();
});

it('rejects creating a cleaning order without quote after enforcement date', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-23 10:00:00'));

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
        ],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '09:00',
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'termsAccepted' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors(['quoteId']);
});

it('rejects creating a cleaning order with an expired quote', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-10 08:00:00'));

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $priceResponse = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'addressLatitude' => 33.5,
        'addressLongitude' => 36.3,
    ])->assertOk();

    $quoteId = (string) $priceResponse->json('quote.quoteId');

    Carbon::setTestNow(now()->addMinutes(16));

    postJson('/api/v1/user/cleaning/orders', [
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
        'quoteId' => $quoteId,
        'termsAccepted' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors(['quoteId']);
});

it('rejects creating a cleaning order with mismatched quote details', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $priceResponse = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'addressLatitude' => 33.5,
        'addressLongitude' => 36.3,
    ])->assertOk();

    $quoteId = (string) $priceResponse->json('quote.quoteId');

    postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Al Aziziyah Street, Building 12',
            'location_name' => 'Home',
            'rooms' => 3,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '09:00',
        'addressLatitude' => 33.5,
        'addressLongitude' => 36.3,
        'quoteId' => $quoteId,
        'termsAccepted' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors(['quoteId']);
});

it('rejects creating a cleaning order with quote issued for another user', function (): void {
    $userOne = User::factory()->create();
    Sanctum::actingAs($userOne);

    $priceResponse = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'addressLatitude' => 33.5,
        'addressLongitude' => 36.3,
    ])->assertOk();

    $quoteId = (string) $priceResponse->json('quote.quoteId');

    $userTwo = User::factory()->create();
    Sanctum::actingAs($userTwo);

    postJson('/api/v1/user/cleaning/orders', [
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
        'quoteId' => $quoteId,
        'termsAccepted' => true,
    ])->assertUnprocessable()->assertJsonValidationErrors(['quoteId']);
});

it('allows schedule-only update without quote after enforcement date', function (): void {
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

it('rejects price-affecting update without quote after enforcement date', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-23 09:00:00'));

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
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors(['quoteId']);
});

it('updates property type with valid quote and recalculates totals', function (): void {
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

    $quoteId = (string) $priceResponse->json('quote.quoteId');
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
        'quoteId' => $quoteId,
    ])->assertOk()->assertJsonPath('order.propertyType', 'villa');

    $order->refresh();
    expect((string) $order->property_type)->toBe('villa');
    expect((float) $order->total_price)->toBe($expectedTotalPrice);
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
