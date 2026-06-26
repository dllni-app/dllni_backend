<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Models\CleaningBillingPolicy;

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

it('allows same day cleaning orders and marks them as hot orders without changing the price equation', function (): void {
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
        ],
        'scheduledDate' => now()->format('Y-m-d'),
        'scheduledTime' => '09:00',
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'termsAccepted' => true,
    ];

    $response = postJson('/api/v1/user/cleaning/orders', $payload);

    $response
        ->assertCreated()
        ->assertJsonPath('order.scheduledDate', now()->format('Y-m-d'));

    $orderId = (int) $response->json('order.id');
    $propertyDetails = DB::table('cleaning_bookings')
        ->where('id', $orderId)
        ->value('property_details');

    $storedDetails = json_decode((string) $propertyDetails, true, flags: JSON_THROW_ON_ERROR);

    expect($storedDetails['is_hot_order'] ?? false)->toBeTrue()
        ->and($storedDetails['hot_order_title'] ?? '')->toStartWith('[🚨 طلب ساخن - تنفيذ فوري عاجل]')
        ->and((float) DB::table('cleaning_bookings')->where('id', $orderId)->value('total_price'))->toBeGreaterThan(0);
});
