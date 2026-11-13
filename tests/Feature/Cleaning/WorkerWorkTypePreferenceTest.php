<?php

declare(strict_types=1);

use App\Models\Worker;
use Modules\Cleaning\Models\CleaningBooking;

it('defaults workers with removed legacy preferences to all order types', function (): void {
    $worker = new Worker;

    expect($worker->preferredWorkTypes())->toBe(['cleaning', 'events', 'hourly']);
});

it('matches cleaning, event, and hourly booking categories against saved choices', function (): void {
    $worker = new Worker;
    $worker->preferred_work_types = ['events', 'hourly'];

    $cleaning = new CleaningBooking;
    $cleaning->property_type = 'apartment';
    $cleaning->booking_kind = 'standard';

    $events = new CleaningBooking;
    $events->property_type = 'event_assistance';
    $events->booking_kind = 'standard';

    $hourly = new CleaningBooking;
    $hourly->property_type = 'apartment';
    $hourly->booking_kind = 'open_time';

    expect($worker->preferredWorkTypes())->toBe(['events', 'hourly'])
        ->and($worker->acceptsBookingType($cleaning))->toBeFalse()
        ->and($worker->acceptsBookingType($events))->toBeTrue()
        ->and($worker->acceptsBookingType($hourly))->toBeTrue();
});
