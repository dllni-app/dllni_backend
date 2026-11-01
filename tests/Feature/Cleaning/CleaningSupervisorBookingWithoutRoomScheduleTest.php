<?php

declare(strict_types=1);

use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningSupervisorBookingScheduleService;

it('allows supervisor scheduling for a standard group without room schedules', function (): void {
    $booking = new CleaningBooking();
    $booking->forceFill([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'estimated_hours' => 4,
        'scheduled_date' => '2026-11-23',
        'scheduled_time' => '09:00',
    ]);

    $service = app(CleaningSupervisorBookingScheduleService::class);
    expect($service->isEligible($booking))->toBeTrue()
        ->and($service->intervals($booking))->toHaveCount(1);
    [$start, $end] = $service->intervals($booking)[0];
    expect($start->format('H:i'))->toBe('09:00')
        ->and($end->format('H:i'))->toBe('11:00');
});
