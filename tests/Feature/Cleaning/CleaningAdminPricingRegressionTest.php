<?php

declare(strict_types=1);

use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingAdminPricingService;

it('allows an admin to change room quantities with a server-side price and duration recalculation', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'booking_kind' => 'standard',
        'property_type' => 'apartment',
        'address_latitude' => 36.20,
        'address_longitude' => 37.15,
        'property_details' => [
            'cleaning_mode' => 'regular',
            'room_size_breakdown' => [
                'bedroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
                'bathroom' => ['small' => 1, 'medium' => 0, 'large' => 0],
            ],
        ],
    ]);

    $updated = app(CleaningBookingAdminPricingService::class)
        ->setRoomQuantity($booking, 'bedroom', 'small', 2);

    expect((int) data_get($updated->property_details, 'room_size_breakdown.bedroom.small'))->toBe(2)
        ->and($updated->rooms()->where('room_type', 'bedroom')->count())->toBe(2)
        ->and((float) $updated->base_price)->toBeGreaterThan(0)
        ->and((float) $updated->estimated_hours)->toBeGreaterThan(0)
        ->and($updated->status)->toBe(CleaningBookingStatus::Pending);
});

it('blocks administrative price edits after worker travel has started', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'started_travel_at' => now(),
        'booking_kind' => 'standard',
        'property_type' => 'apartment',
    ]);

    expect(fn () => app(CleaningBookingAdminPricingService::class)->setBasePrice($booking, 5000))
        ->toThrow(InvalidArgumentException::class);
});
