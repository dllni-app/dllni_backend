<?php

declare(strict_types=1);

use Modules\Cleaning\Services\CleaningV3WorkerBookingReadProjection;

test('V3 worker offer only includes coarse district and authorized visit slots', function (): void {
    $payload = (new CleaningV3WorkerBookingReadProjection)->present([
        'id' => 84,
        'bookingNumber' => 'CLI-84',
        'status' => 'pending',
        'locationSummary' => 'المركز',
        'customerName' => 'PRIVATE',
        'customerPhone' => 'PRIVATE',
        'addressLatitude' => 36.3,
        'addressLongitude' => 37.1,
        'streetAddress' => 'PRIVATE',
        'payment' => ['token' => 'PRIVATE'],
    ], [
        [
            'sessionId' => 1001,
            'scheduledDate' => '2026-12-20',
            'scheduledTime' => '09:00',
            'status' => 'scheduled',
            'customerNotes' => 'PRIVATE',
            'otherWorkerIds' => [2, 3],
            'addressLatitude' => 36.3,
        ],
    ]);

    expect($payload['contractVersion'])->toBe(3)
        ->and($payload['locationSummary'])->toBe('المركز')
        ->and($payload['sessions'])->toHaveCount(1)
        ->and($payload['sessions'][0]['sessionId'])->toBe(1001)
        ->and($payload)->not->toHaveKeys([
            'customerName', 'customerPhone', 'addressLatitude',
            'addressLongitude', 'streetAddress', 'payment',
        ])
        ->and($payload['sessions'][0])->not->toHaveKeys([
            'customerNotes', 'otherWorkerIds', 'addressLatitude',
        ]);
});

test('V3 booking-only read does not automatically reveal unrelated sibling or stale metadata', function (): void {
    $presenter = new CleaningV3WorkerBookingReadProjection;
    $result = $presenter->present(
        ['id' => 80, 'bookingNumber' => 'CLI-80', 'status' => 'worker_assigned', 'locationSummary' => 'الفرقان'],
        [
            ['sessionId' => 401, 'status' => 'worker_assigned'],
            ['sessionId' => 401, 'status' => 'cancelled', 'address' => 'PRIVATE'],
            ['sessionId' => -1, 'status' => 'scheduled'],
        ],
    );

    expect($result['sessions'])->toHaveCount(1)
        ->and($result['sessions'][0]['sessionId'])->toBe(401)
        ->and(json_encode($result))->not->toContain('PRIVATE')
        ->and($result['sessions'][0])->not->toHaveKey('address');
});
