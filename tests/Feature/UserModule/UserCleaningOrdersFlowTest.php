<?php

declare(strict_types=1);

use App\Models\BookingReview;
use App\Models\CancellationPolicy;
use App\Models\CleaningFinancialSetting;
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
use Modules\User\Services\UserCleaningOrderEstimationService;

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
    expect($response->json('order.propertyDetails.cleaning_mode'))->toBe('regular');
});

it('creates a deep cleaning order and persists the mode in the response payload', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $payload = [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Damascus - Mazzeh',
            'location_name' => 'Home',
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
            'cleaning_mode' => 'deep',
        ],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '09:00',
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'termsAccepted' => true,
    ];

    $priceResponse = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => $payload['propertyType'],
        'propertyDetails' => $payload['propertyDetails'],
        'addressLatitude' => $payload['addressLatitude'],
        'addressLongitude' => $payload['addressLongitude'],
    ])->assertOk();

    $response = postJson('/api/v1/user/cleaning/orders', $payload);

    $response->assertCreated();
    expect($response->json('order.propertyDetails.cleaning_mode'))->toBe('deep');
    expect((float) $response->json('order.basePrice'))->toBe((float) $priceResponse->json('pricing.basePrice'));
    expect((float) $response->json('order.totalPrice'))->toBe((float) $priceResponse->json('pricing.totalPrice'));
});

it('creates an open count order when preferred worker mode is requested without a preferred worker', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

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
        'assignmentMode' => 'preferred_worker',
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '09:00',
        'termsAccepted' => true,
    ]);

    $response->assertCreated();
    expect($response->json('order.assignmentMode'))->toBe('open_count');
    expect($response->json('order.preferredWorker'))->toBeNull();
    expect($response->json('order.isPricingFinal'))->toBeFalse();
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
        'extendedTimeRanges' => [
            '*' => ['id', 'startMinutes', 'endMinutes', 'label', 'price', 'currency'],
        ],
    ]);

    expect((float) $response->json('size.estimatedSqm'))->toBe(171.0);
    expect((string) $response->json('size.sizeTier'))->toBe('large');
    expect((float) $response->json('estimation.estimatedHours'))->toBe(5.5);
    expect((int) $response->json('estimation.estimatedMinutes'))->toBe(330);
    expect($response->json('extendedTimeRanges.0'))->toMatchArray([
        'startMinutes' => 0,
        'endMinutes' => 15,
        'label' => '0 - 15 minutes',
        'price' => 2250.0,
        'currency' => 'SYP',
    ]);
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
        'extendedTimeRanges' => [
            '*' => ['id', 'startMinutes', 'endMinutes', 'label', 'price', 'currency'],
        ],
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
    expect($response->json('extendedTimeRanges.1'))->toMatchArray([
        'startMinutes' => 16,
        'endMinutes' => 30,
        'label' => '16 - 30 minutes',
        'price' => 4500.0,
        'currency' => 'SYP',
    ]);
    expect((string) $response->json('algorithmVersion'))->toBe(UserCleaningOrderEstimationService::ALGORITHM_VERSION);
});

it('allows preferred worker mode to fall back to open count when no preferred worker is selected', function (): void {
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
        'assignmentMode' => 'preferred_worker',
    ]);

    $response->assertOk();
    expect($response->json('pricing.isPricingFinal'))->toBeFalse();
    expect($response->json('pricing.travelFee'))->toBe(0);
    expect($response->json('pricing.distanceKm'))->toBeNull();
});

it('accepts team worker room assignments on estimate-price and returns weighted pricing preview', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'rooms' => 4,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'kitchens' => 1,
            'living_room_size' => 'medium',
            'room_size_breakdown' => [
                'bedroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'bathroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'kitchen' => ['small' => 0, 'medium' => 1, 'large' => 0],
                'living_room' => ['small' => 0, 'medium' => 1, 'large' => 0],
                'balcony' => ['small' => 0, 'medium' => 0, 'large' => 0],
            ],
        ],
        'assignmentMode' => 'open_count',
        'numberOfWorkers' => 2,
        'workerRoomAssignments' => [
            [
                'workerSlot' => 1,
                'preferredWorkerId' => null,
                'rooms' => [
                    ['roomKey' => 'bedroom.small.1', 'roomType' => 'bedroom', 'roomSize' => 'small'],
                ],
            ],
            [
                'workerSlot' => 2,
                'preferredWorkerId' => null,
                'rooms' => [
                    ['roomKey' => 'bathroom.small.1', 'roomType' => 'bathroom', 'roomSize' => 'small'],
                ],
            ],
        ],
    ]);

    $response->assertOk();
    expect($response->json('workerRoomAssignments'))->toHaveCount(2);
    expect($response->json('workerRoomAssignments.0.workerSlot'))->toBe(1);
    expect($response->json('workerRoomAssignments.0.roomsWeight'))->toBeGreaterThan(0);
    expect($response->json('workerRoomAssignments.0.estimatedServiceShareAmount'))->toBeGreaterThan(0);
    expect(collect($response->json('workerRoomAssignments.1.rooms'))->pluck('roomKey'))->toContain('living_room.medium.1');
});

it('accepts preferred worker room assignments on estimate-price', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $preferredWorker = Worker::factory()->create([
        'home_address' => 'Preferred Worker Home',
        'home_latitude' => 33.55,
        'home_longitude' => 36.31,
    ]);

    $response = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
            'room_size_breakdown' => [
                'bedroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'bathroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'kitchen' => ['small' => 0, 'medium' => 0, 'large' => 0],
                'living_room' => ['small' => 0, 'medium' => 1, 'large' => 0],
                'balcony' => ['small' => 0, 'medium' => 0, 'large' => 0],
            ],
        ],
        'assignmentMode' => 'preferred_worker',
        'preferredWorkerId' => $preferredWorker->id,
        'numberOfWorkers' => 1,
        'addressLatitude' => 33.5,
        'addressLongitude' => 36.3,
        'workerRoomAssignments' => [
            [
                'workerSlot' => 1,
                'preferredWorkerId' => $preferredWorker->id,
                'rooms' => [
                    ['roomKey' => 'bedroom.small.1', 'roomType' => 'bedroom', 'roomSize' => 'small'],
                ],
            ],
        ],
    ]);

    $response->assertOk();
    expect($response->json('workerRoomAssignments'))->toHaveCount(1);
    expect($response->json('workerRoomAssignments.0.preferredWorkerId'))->toBe($preferredWorker->id);
    expect(collect($response->json('workerRoomAssignments.0.rooms'))->pluck('roomKey'))->toContain('living_room.medium.1');
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
                'balcony' => ['small' => 0, 'medium' => 0, 'large' => 0],
            ],
        ],
    ]);

    $response->assertOk();
    expect((float) $response->json('size.estimatedSqm'))->toBe(302.0);
    expect((float) $response->json('size.estimatedHours'))->toBe(10.0);
    expect((string) $response->json('size.sizeTier'))->toBe('very_large');
    expect((float) $response->json('pricing.basePrice'))->toBe(2416.0);
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
                'balcony' => ['small' => 0, 'medium' => 0, 'large' => 0],
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
    expect($response->json('order.propertyDetails.balconies'))->toBe(0);
    expect($response->json('order.propertyDetails.living_room_size'))->toBe('medium');
    expect($response->json('order.propertyDetails.room_size_breakdown.bedroom.large'))->toBe(1);
});

it('accepts partial room_size_breakdown for estimate-price and treats missing counts as zero', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $preferredWorker = Worker::factory()->create([
        'home_address' => 'Preferred Worker Home',
        'home_latitude' => 36.205,
        'home_longitude' => 37.12,
    ]);

    $response = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'villa',
        'propertyDetails' => [
            'bedrooms' => 2,
            'rooms' => 1,
            'bathrooms' => 1,
            'balconies' => 0,
            'living_room_size' => 'small',
            'room_size_breakdown' => [
                'bedroom' => [
                    'large' => 1,
                ],
                'bathroom' => [
                    'medium' => 1,
                ],
            ],
            'cleaning_mode' => 'deep',
        ],
        'addressLatitude' => 36.2001697,
        'addressLongitude' => 37.1169824,
        'assignmentMode' => 'preferred_worker',
        'preferredWorkerId' => $preferredWorker->id,
        'numberOfWorkers' => 1,
        'workerRoomAssignments' => [
            [
                'workerSlot' => 1,
                'preferredWorkerId' => $preferredWorker->id,
                'rooms' => [
                    ['roomKey' => 'bedroom.large.1', 'roomType' => 'bedroom', 'roomSize' => 'large'],
                    ['roomKey' => 'bathroom.medium.1', 'roomType' => 'bathroom', 'roomSize' => 'medium'],
                ],
            ],
        ],
    ]);

    $response->assertOk();
    expect(collect($response->json('workerRoomAssignments.0.rooms'))->pluck('roomKey')->all())->toBe([
        'bathroom.medium.1',
        'bedroom.large.1',
    ]);
});

it('creates order with partial room_size_breakdown and persists zero-filled normalized breakdown', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'villa',
        'propertyDetails' => [
            'address' => 'Aleppo - New Aleppo',
            'location_name' => 'Villa',
            'bedrooms' => 2,
            'rooms' => 1,
            'bathrooms' => 1,
            'balconies' => 0,
            'living_room_size' => 'small',
            'room_size_breakdown' => [
                'bedroom' => [
                    'large' => 1,
                ],
                'bathroom' => [
                    'medium' => 1,
                ],
            ],
            'cleaning_mode' => 'deep',
        ],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '10:00',
        'termsAccepted' => true,
    ]);

    $response->assertCreated();
    expect($response->json('order.propertyDetails.bedrooms'))->toBe(2);
    expect($response->json('order.propertyDetails.rooms'))->toBe(1);
    expect($response->json('order.propertyDetails.bathrooms'))->toBe(1);
    expect($response->json('order.propertyDetails.kitchens'))->toBe(0);
    expect($response->json('order.propertyDetails.living_room_size'))->toBe('small');
    expect($response->json('order.propertyDetails.room_size_breakdown.bedroom.small'))->toBe(0);
    expect($response->json('order.propertyDetails.room_size_breakdown.bedroom.large'))->toBe(1);
    expect($response->json('order.propertyDetails.room_size_breakdown.kitchen.medium'))->toBe(0);
});

it('creates team order with worker room assignments and persists planned slots', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Damascus - Kafar Souseh',
            'location_name' => 'Home',
            'rooms' => 4,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'kitchens' => 1,
            'living_room_size' => 'medium',
            'room_size_breakdown' => [
                'bedroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'bathroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'kitchen' => ['small' => 0, 'medium' => 1, 'large' => 0],
                'living_room' => ['small' => 0, 'medium' => 1, 'large' => 0],
                'balcony' => ['small' => 0, 'medium' => 0, 'large' => 0],
            ],
        ],
        'assignmentMode' => 'open_count',
        'numberOfWorkers' => 2,
        'workerRoomAssignments' => [
            [
                'workerSlot' => 1,
                'preferredWorkerId' => null,
                'rooms' => [
                    ['roomKey' => 'bedroom.small.1', 'roomType' => 'bedroom', 'roomSize' => 'small'],
                    ['roomKey' => 'bathroom.small.1', 'roomType' => 'bathroom', 'roomSize' => 'small'],
                ],
            ],
            [
                'workerSlot' => 2,
                'preferredWorkerId' => null,
                'rooms' => [
                    ['roomKey' => 'kitchen.medium.1', 'roomType' => 'kitchen', 'roomSize' => 'medium'],
                ],
            ],
        ],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '10:00',
        'termsAccepted' => true,
    ]);

    $response->assertCreated();
    expect($response->json('order.workerRoomAssignments'))->toHaveCount(2);
    expect($response->json('order.workerRoomAssignments.0.workerSlot'))->toBe(1);
    expect($response->json('order.roomAssignments.0.plannedWorkerSlot'))->not->toBeNull();
    $this->assertDatabaseHas('cleaning_booking_rooms', [
        'cleaning_booking_id' => (int) $response->json('order.id'),
        'room_key' => 'bedroom.small.1',
        'planned_worker_slot' => 1,
    ]);
});

it('creates preferred worker order with worker room assignments', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $preferredWorker = Worker::factory()->create([
        'home_address' => 'Preferred Worker Home',
        'home_latitude' => 33.55,
        'home_longitude' => 36.31,
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
            'room_size_breakdown' => [
                'bedroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'bathroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'kitchen' => ['small' => 0, 'medium' => 0, 'large' => 0],
                'living_room' => ['small' => 0, 'medium' => 1, 'large' => 0],
                'balcony' => ['small' => 0, 'medium' => 0, 'large' => 0],
            ],
        ],
        'assignmentMode' => 'preferred_worker',
        'preferredWorkerId' => $preferredWorker->id,
        'numberOfWorkers' => 1,
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'workerRoomAssignments' => [
            [
                'workerSlot' => 1,
                'preferredWorkerId' => $preferredWorker->id,
                'rooms' => [
                    ['roomKey' => 'bedroom.small.1', 'roomType' => 'bedroom', 'roomSize' => 'small'],
                ],
            ],
        ],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '09:30',
        'termsAccepted' => true,
    ]);

    $response->assertCreated();
    expect($response->json('order.workerRoomAssignments'))->toHaveCount(1);
    expect($response->json('order.workerRoomAssignments.0.preferredWorkerId'))->toBe($preferredWorker->id);
    $this->assertDatabaseHas('cleaning_booking_rooms', [
        'cleaning_booking_id' => (int) $response->json('order.id'),
        'room_key' => 'bedroom.small.1',
        'planned_worker_slot' => 1,
        'planned_preferred_worker_id' => $preferredWorker->id,
    ]);
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
                'balcony' => ['small' => 0, 'medium' => 0, 'large' => 0],
            ],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'propertyDetails.room_size_breakdown.bedroom',
    ]);
});

it('validates provided partial room_size_breakdown bucket values', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'room_size_breakdown' => [
                'bedroom' => ['large' => -1],
                'bathroom' => ['medium' => 1.5],
            ],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'propertyDetails.room_size_breakdown.bedroom.large',
        'propertyDetails.room_size_breakdown.bathroom.medium',
    ]);
});

it('rejects invalid worker room assignments', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
            'room_size_breakdown' => [
                'bedroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'bathroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'kitchen' => ['small' => 0, 'medium' => 0, 'large' => 0],
                'living_room' => ['small' => 0, 'medium' => 1, 'large' => 0],
                'balcony' => ['small' => 0, 'medium' => 0, 'large' => 0],
            ],
        ],
        'assignmentMode' => 'open_count',
        'numberOfWorkers' => 1,
        'workerRoomAssignments' => [
            [
                'workerSlot' => 2,
                'preferredWorkerId' => null,
                'rooms' => [
                    ['roomKey' => 'missing.room.1', 'roomType' => 'bedroom', 'roomSize' => 'small'],
                ],
            ],
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'workerRoomAssignments.0.workerSlot',
        'workerRoomAssignments.0.rooms.0.roomKey',
    ]);
});

it('rejects invalid cleaning mode values in user cleaning requests', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
            'cleaning_mode' => 'ultra',
        ],
    ])->assertUnprocessable()->assertJsonValidationErrors([
        'propertyDetails.cleaning_mode',
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

it('returns deep cleaning estimate with selected services while keeping add-ons unchanged', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $serviceA = CleaningService::query()->create([
        'name' => 'Deep clean add-on',
        'slug' => 'deep-clean-addon-flow-'.fake()->unique()->numerify('###'),
        'category' => ServiceCategory::Cleaning->value,
        'description' => 'Deep cleaning',
        'is_active' => true,
    ]);
    $serviceB = CleaningService::query()->create([
        'name' => 'Kitchen add-on',
        'slug' => 'kitchen-clean-addon-flow-'.fake()->unique()->numerify('###'),
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
            'cleaning_mode' => 'deep',
        ],
        'serviceIds' => [$serviceA->id, $serviceB->id],
    ]);

    $response->assertOk();
    expect((float) $response->json('pricing.basePrice'))->toBe(4600.0);
    expect((float) $response->json('pricing.addonsTotal'))->toBe(180.0);
    expect((float) $response->json('pricing.totalPrice'))->toBe(4780.0);
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

it('creates regular cleaning order with selected service names', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

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
        'cleaning_services' => ['Balcony cleaning', 'Window cleaning'],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '10:30',
        'termsAccepted' => true,
    ]);

    $response->assertCreated();
    $orderId = (int) $response->json('order.id');

    expect($response->json('order.cleaning_services'))->toBe(['Balcony cleaning', 'Window cleaning']);
    expect((float) $response->json('order.addonsTotal'))->toBe(0.0);
    expect((float) $response->json('order.totalPrice'))->toBe(920.0);

    $booking = CleaningBooking::query()->findOrFail($orderId);
    expect($booking->cleaning_services)->toBe(['Balcony cleaning', 'Window cleaning']);
    expect(DB::table('cleaning_booking_service')->where('cleaning_booking_id', $orderId)->count())->toBe(0);
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

it('recalculates totals when cleaning mode changes', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-04-23 09:00:00'));

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $order = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::Pending->value,
        'property_type' => 'apartment',
        'property_details' => [
            'address' => 'Damascus - Kafar Souseh',
            'location_name' => 'Home',
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
    ]);

    $priceResponse = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Damascus - Kafar Souseh',
            'location_name' => 'Home',
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
            'cleaning_mode' => 'deep',
        ],
    ])->assertOk();

    $expectedTotalPrice = (float) $priceResponse->json('pricing.totalPrice');

    patchJson("/api/v1/user/cleaning/orders/{$order->id}", [
        'propertyDetails' => [
            'cleaning_mode' => 'deep',
        ],
    ])->assertOk()
        ->assertJsonPath('order.propertyDetails.address', 'Damascus - Kafar Souseh')
        ->assertJsonPath('order.propertyDetails.cleaning_mode', 'deep');

    $order->refresh();
    expect((string) $order->property_details['cleaning_mode'])->toBe('deep');
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

it('updates regular cleaning order service names', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $create = postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Damascus',
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
        'cleaning_services' => ['Regular service A'],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '16:00',
        'termsAccepted' => true,
    ])->assertCreated();

    $orderId = (int) $create->json('order.id');

    $response = patchJson("/api/v1/user/cleaning/orders/{$orderId}", [
        'cleaning_services' => ['Regular service B', 'Regular service C'],
        'propertyDetails' => [
            'address' => 'Damascus updated',
            'rooms' => 2,
            'bedrooms' => 1,
            'bathrooms' => 1,
            'living_room_size' => 'small',
        ],
    ])->assertOk();

    expect($response->json('order.cleaning_services'))->toBe(['Regular service B', 'Regular service C']);

    $booking = CleaningBooking::query()->findOrFail($orderId);
    expect($booking->cleaning_services)->toBe(['Regular service B', 'Regular service C']);
    expect(DB::table('cleaning_booking_service')->where('cleaning_booking_id', $orderId)->count())->toBe(0);
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

it('estimates event assistance pricing from selected hours instead of selected services', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        ['extension_rate_per_30_minutes' => 150]
    );

    $response = postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'eventType' => 'birthday',
            'guestCount' => 45,
            'venueType' => 'apartment',
            'customService' => 'Serving and cleanup support',
            'hours' => 4,
        ],
    ]);

    $response->assertOk();
    expect((float) $response->json('pricing.basePrice'))->toBe(1200.0);
    expect((float) $response->json('pricing.totalPrice'))->toBe(1200.0);
    expect((float) $response->json('pricing.eventHourlyRate'))->toBe(300.0);
    expect((float) $response->json('pricing.eventHours'))->toBe(4.0);
    expect((float) $response->json('size.estimatedHours'))->toBe(4.0);
    expect($response->json('recommendation.guestCount'))->toBe(45);
    expect($response->json('recommendation.customService'))->toBe('Serving and cleanup support');
    expect($response->json('recommendation.suggestedTeamSize'))->toBe(5);
    expect($response->json('pricing.serviceLines'))->toHaveCount(0);
});

it('creates event assistance order with custom service and does not sync booking services pivot', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        ['extension_rate_per_30_minutes' => 150]
    );

    $response = postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'address' => 'Damascus, Mazzeh',
            'location_name' => 'Family Hall',
            'eventType' => 'family_dinner',
            'guestCount' => 40,
            'venueType' => 'apartment',
            'customService' => 'Manual hospitality support',
            'hours' => 5,
            'specialRequirement' => 'Male helpers only',
            'notes' => 'Call before arrival',
        ],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '18:30',
        'genderPreference' => 'male',
        'termsAccepted' => true,
    ]);

    $response->assertCreated();
    $orderId = (int) $response->json('order.id');

    expect($response->json('order.propertyType'))->toBe('event_assistance');
    expect($response->json('order.genderPreference'))->toBe('male');
    expect($response->json('order.numberOfWorkers'))->toBe(4);
    expect($response->json('order.propertyDetails.custom_service'))->toBe('Manual hospitality support');
    expect((float) $response->json('order.propertyDetails.hours'))->toBe(5.0);
    expect((float) $response->json('order.basePrice'))->toBe(1500.0);
    expect((float) $response->json('order.totalHours'))->toBe(5.0);

    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $orderId,
        'property_type' => 'event_assistance',
        'gender_preference' => 'male',
    ]);
    expect(DB::table('cleaning_booking_service')->where('cleaning_booking_id', $orderId)->count())->toBe(0);
});

it('updates event assistance hours and custom service without service-based pricing', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        ['extension_rate_per_30_minutes' => 150]
    );

    $create = postJson('/api/v1/user/cleaning/orders', [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'address' => 'Damascus',
            'eventType' => 'birthday',
            'guestCount' => 25,
            'venueType' => 'apartment',
            'customService' => 'Initial manual support',
            'hours' => 2,
        ],
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
            'customService' => 'Updated manual support',
            'hours' => 6,
            'notes' => 'Need early arrival',
        ],
    ]);

    $update->assertOk();
    expect((float) $update->json('order.basePrice'))->toBe(1800.0);
    expect((float) $update->json('order.totalHours'))->toBe(6.0);
    expect($update->json('order.propertyDetails.event_type'))->toBe('large_gathering');
    expect($update->json('order.propertyDetails.guest_count'))->toBe(60);
    expect($update->json('order.propertyDetails.custom_service'))->toBe('Updated manual support');

    expect(DB::table('cleaning_booking_service')->where('cleaning_booking_id', $orderId)->count())->toBe(0);
});

it('validates required event assistance fields and rejects serviceIds', function (): void {
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
        'propertyDetails.customService',
        'propertyDetails.hours',
    ]);

    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        ['extension_rate_per_30_minutes' => 150]
    );

    postJson('/api/v1/user/cleaning/orders/estimate-price', [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'eventType' => 'birthday',
            'guestCount' => 20,
            'venueType' => 'apartment',
            'customService' => 'Manual support',
            'hours' => 2,
        ],
        'serviceIds' => [999],
    ])->assertUnprocessable()->assertJsonValidationErrors(['serviceIds']);
});
