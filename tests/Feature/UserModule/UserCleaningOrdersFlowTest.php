<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use App\Models\Worker;
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
    ]);

    expect((float) $response->json('pricing.totalPrice'))->toBeGreaterThan(0);
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
