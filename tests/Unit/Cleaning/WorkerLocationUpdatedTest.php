<?php

declare(strict_types=1);

use Modules\Cleaning\Events\WorkerLocationUpdated;

it('includes booking and worker identity in location broadcasts', function (): void {
    $event = new WorkerLocationUpdated(
        cleaningBookingId: 321,
        latitude: 33.5138,
        longitude: 36.2765,
        workerId: 17,
    );

    $payload = $event->broadcastWith();

    expect($payload)
        ->toMatchArray([
            'cleaningBookingId' => 321,
            'bookingId' => 321,
            'latitude' => 33.5138,
            'longitude' => 36.2765,
            'workerId' => 17,
        ])
        ->and($payload['updatedAt'])->toBeString()->not->toBeEmpty();
});
