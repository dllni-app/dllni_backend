<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBillingMode;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;

it('materializes the supported sole room-period visit with stable room links and parent financials', function (): void {
    $customer = User::factory()->create();
    Sanctum::actingAs($customer);
    CancellationPolicy::query()->create([
        'module' => 'cleaning',
        'name' => 'B1 room-period policy',
        'description' => 'B1',
        'rules' => ['free_until_hours' => 24],
        'is_active' => true,
        'is_default' => true,
    ]);
    CleaningBillingPolicy::query()->create([
        'name' => 'B1 room-period billing',
        'billing_mode' => CleaningBillingMode::FullBookedTime->value,
        'rules' => [],
        'is_active' => true,
        'is_default' => true,
    ]);

    $date = now(config('app.timezone'))->addDays(3)->toDateString();
    $payload = [
        'propertyType' => 'apartment',
        'bookingKind' => 'standard',
        'propertyDetails' => [
            'address' => 'B1 isolated test address',
            'rooms' => 2,
            'bedrooms' => 2,
            'cleaning_mode' => 'regular',
            'room_size_breakdown' => [
                'bedroom' => ['small' => 1, 'medium' => 1, 'large' => 0],
            ],
        ],
        'scheduledDate' => $date,
        'scheduledTime' => '09:00',
        'addressLatitude' => 33.5138,
        'addressLongitude' => 36.2765,
        'numberOfWorkers' => 2,
        'assignmentMode' => 'open_count',
        'termsAccepted' => true,
        'roomPeriods' => [
            [
                'clientKey' => 'bedroom.1',
                'visitSequence' => 1,
                'roomType' => 'bedroom',
                'size' => 'small',
                'cleaningMode' => 'deep',
                'scheduledDate' => $date,
                'scheduledTime' => '09:00',
                'numberOfWorkers' => 2,
                'materials' => ['providedByPlatform' => false, 'materialTypeIds' => []],
                'specialServices' => [],
            ],
            [
                'clientKey' => 'bedroom.2',
                'visitSequence' => 1,
                'roomType' => 'bedroom',
                'size' => 'medium',
                'cleaningMode' => 'regular',
                'scheduledDate' => $date,
                'scheduledTime' => '11:00',
                'numberOfWorkers' => 1,
                'materials' => ['providedByPlatform' => false, 'materialTypeIds' => []],
                'specialServices' => [],
            ],
        ],
    ];

    $estimate = $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $payload)
        ->assertOk();
    $payload['quoteSignature'] = $estimate->json('quoteSignature');

    $response = $this->postJson('/api/v1/user/cleaning/orders', $payload)
        ->assertCreated();
    $booking = CleaningBooking::query()->findOrFail((int) $response->json('order.id'));
    $session = $booking->sessions()->sole();
    $periods = $booking->roomPeriods()->with('room')->get();

    expect($session->sequence)->toBe(1)
        ->and($session->supervision_mode)->toBe(CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR)
        ->and($session->supervision_initialized_at)->not->toBeNull()
        ->and((float) $session->base_price)->toBe((float) $booking->base_price)
        ->and((float) $session->addons_total)->toBe((float) $booking->addons_total)
        ->and((float) $session->travel_fee)->toBe((float) $booking->travel_fee)
        ->and((float) $session->admin_margin_amount)->toBe((float) $booking->admin_margin_amount)
        ->and((float) $session->extension_fee_total)->toBe((float) $booking->extension_fee_total)
        ->and((float) $session->total_price)->toBe((float) $booking->total_price)
        ->and((float) data_get($booking->property_details, '_room_period_v2_approved_total'))
        ->toBe((float) $booking->total_price)
        ->and($periods)->toHaveCount(2)
        ->and($periods->every(fn ($period): bool => $period->cleaning_booking_room_id !== null
            && $period->room !== null
            && (int) $period->room->cleaning_booking_id === (int) $booking->id
            && (int) $period->cleaning_booking_session_id === (int) $session->id
        ))->toBeTrue();
});
