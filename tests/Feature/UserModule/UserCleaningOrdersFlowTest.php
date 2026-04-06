<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Enums\CleaningBookingStatus;
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
        ],
        'estimatedSqm' => 120,
        'totalHours' => 3,
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '09:00',
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'basePrice' => 1000,
        'travelFee' => 200,
        'addonsTotal' => 0,
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
        ->where('base_price', 1000)
        ->where('travel_fee', 200)
        ->where('total_price', 1200)
        ->exists())->toBeTrue();
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
        'totalHours' => 4,
    ]);

    $response->assertOk()->assertJsonPath('order.scheduledTime', '11:00');

    $order->refresh();
    expect((string) $order->scheduled_time)->toStartWith('11:00');
    expect((float) $order->total_hours)->toBe(4.0);
});

it('cancels pending cleaning order and rejects cancelling completed order', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $pendingOrder = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    postJson("/api/v1/user/cleaning/orders/{$pendingOrder->id}/cancel", [
        'reason' => 'Changed plans',
    ])->assertOk()->assertJsonPath('order.status', CleaningBookingStatus::Cancelled->value);

    $completedOrder = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::Completed->value,
    ]);

    postJson("/api/v1/user/cleaning/orders/{$completedOrder->id}/cancel", [
        'reason' => 'Too late',
    ])->assertUnprocessable();
});
