<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\CleaningFinancialSetting;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingTeamService;
use Modules\Cleaning\Models\CleaningRoomType;
use App\Filament\Resources\CleaningRoomTypes\CleaningRoomTypeResource;
use Modules\User\Services\UserCleaningOrderEstimationService;

beforeEach(function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'default_commission_rate' => 0,
        'commission_type' => 'percent',
        'travel_markup_type' => 'worker_allowance',
        'travel_markup_value' => 0,
        'cleaning_minimum_order_price' => 0,
        'supervisor_hourly_rate' => 180,
    ]);
    CleaningRoomType::query()->where('code', 'bedroom')->update([
        'regular_hourly_rate' => 120,
        'deep_hourly_rate' => 300,
        'suggested_minutes_by_size' => json_encode([
            'small' => ['regular' => 30, 'deep' => 60],
            'medium' => ['regular' => 45, 'deep' => 90],
            'large' => ['regular' => 60, 'deep' => 120],
        ]),
    ]);
});

it('publishes active room labels, icons, rates and configurable suggested durations', function (): void {
    CleaningRoomType::query()->where('code', 'bathroom')->update(['is_active' => false]);
    Sanctum::actingAs(User::factory()->create());

    $response = $this->getJson('/api/v1/user/cleaning/room-types')->assertOk()
        ->assertJsonPath('data.0.code', 'bedroom')
        ->assertJsonPath('data.0.iconKey', 'bedroom')
        ->assertJsonPath('data.0.regularHourlyRate', 120)
        ->assertJsonPath('data.0.deepHourlyRate', 300)
        ->assertJsonPath('data.0.suggestedMinutesBySize.small.regularMinutes', 30);
    expect(collect($response->json('data'))->pluck('code'))->not->toContain('bathroom');

    $this->getJson('/api/v1/cleaning/suite-config')->assertOk()
        ->assertJsonPath('data.capabilities.roomHourlyPricing', true)
        ->assertJsonPath('data.roomTypes.0.suggestedMinutesBySize.small.deepMinutes', 60);
});

it('preserves room catalog identities as soft-deactivated entries for historic bookings', function (): void {
    $room = CleaningRoomType::query()->where('code', 'bedroom')->firstOrFail();
    expect(CleaningRoomTypeResource::canDelete($room))->toBeFalse();

    $room->update(['is_active' => false]);
    expect(CleaningRoomType::query()->where('code', 'bedroom')->exists())->toBeTrue();
    expect(CleaningRoomType::availableCodes())->not->toContain('bedroom');
    Sanctum::actingAs(User::factory()->create());
    $this->getJson('/api/v1/user/cleaning/room-types')
        ->assertOk()->assertJsonMissing(['code' => 'bedroom']);
});

it('prices each room by independent mode hourly rate and accepts a customer hours override', function (): void {
    $service = app(UserCleaningOrderEstimationService::class);
    $details = ['room_size_breakdown' => ['bedroom' => ['small' => 1]], 'cleaning_mode' => 'regular'];
    $suggested = $service->price('apartment', $details, null, null);
    $overridden = $service->price('apartment', [
        ...$details,
        'room_estimated_hours' => ['bedroom.small.1' => 1.5],
    ], null, null);
    $deep = $service->price('apartment', [...$details, 'cleaning_mode' => 'deep'], null, null);

    expect((float) $suggested['basePrice'])->toBe(60.0)
        ->and((float) $overridden['basePrice'])->toBe(180.0)
        ->and($overridden['estimatedWorkMinutes'])->toBe(90)
        ->and($overridden['roomPricingLines'][0]['hourlyRate'])->toBe(120.0)
        ->and($overridden['roomPricingLines'][0]['rooms'][0]['suggestedHours'])->toBe(0.5)
        ->and($overridden['roomPricingLines'][0]['rooms'][0]['wasOverridden'])->toBeTrue()
        ->and((float) $deep['basePrice'])->toBe(300.0)
        ->and($deep['roomPricingLines'][0]['hourlyRate'])->toBe(300.0);
});

it('charges supervised group hours once and preserves the supervisor rate snapshot on create', function (): void {
    config()->set('cleaning_supervisor.booking_enabled', true);
    CancellationPolicy::query()->create([
        'module' => 'cleaning', 'name' => 'Supervised test cancellation',
        'description' => 'Test policy', 'rules' => ['free_until_hours' => 24],
        'is_active' => true, 'is_default' => true,
    ]);
    CleaningBillingPolicy::query()->create([
        'name' => 'Supervised test billing',
        'billing_mode' => CleaningBillingMode::FullBookedTime->value,
        'rules' => ['charge_full_booked_hours' => true],
        'is_active' => true, 'is_default' => true,
    ]);
    $user = User::factory()->create();
    Sanctum::actingAs($user);
    $payload = [
        'serviceType' => 'supervised_team',
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Street 12', 'room_size_breakdown' => ['bedroom' => ['small' => 1]],
            'room_estimated_hours' => ['bedroom.small.1' => 2],
        ],
        'scheduledDate' => now()->addDays(2)->format('Y-m-d'),
        'scheduledTime' => '10:00', 'addressLatitude' => 33.5,
        'addressLongitude' => 36.3, 'numberOfWorkers' => 2, 'termsAccepted' => true,
    ];
    $estimate = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $payload)
        ->assertOk()
        ->assertJsonPath('pricing.supervisor.fee', 180)
        ->assertJsonPath('pricing.supervisor.billableMinutes', 60);
    $created = $this->postJson('/api/v1/user/cleaning/orders', $payload)->assertCreated();
    $booking = CleaningBooking::query()->findOrFail($created->json('order.id'));

    expect((float) $booking->base_price)->toBe((float) $estimate->json('pricing.basePrice'))
        ->and((float) $booking->property_details['_supervisor_pricing']['fee'])->toBe(180.0)
        ->and((float) $booking->property_details['_supervisor_pricing']['hourlyRate'])->toBe(180.0)
        ->and((int) $booking->property_details['_supervisor_pricing']['billableMinutes'])->toBe(60);
});

it('keeps the supervisor fee out of worker service shares while preserving the customer total', function (): void {
    $booking = CleaningBooking::factory()->create([
        'property_type' => 'apartment',
        'property_details' => [
            'room_size_breakdown' => ['bedroom' => ['small' => 2]],
            '_supervisor_pricing' => ['hourlyRate' => 120, 'billableMinutes' => 60, 'hours' => 1, 'fee' => 120],
        ],
        'number_of_workers' => 2,
        'base_price' => 400,
        'addons_total' => 0,
        'total_price' => 400,
        'address_latitude' => null,
        'address_longitude' => null,
    ]);
    $service = app(CleaningBookingTeamService::class);
    $service->syncRooms($booking);
    $worker = Worker::factory()->create();
    $booking->workerAssignments()->create(['worker_id' => $worker->id, 'status' => 'accepted']);

    $updated = $service->recalculateBookingTeam($booking, false, false);
    expect((float) $updated->workerAssignments()->firstOrFail()->service_share_amount)->toBe(140.0)
        ->and((float) $updated->base_price)->toBe(400.0)
        ->and((float) $updated->total_price)->toBe(400.0);
});
