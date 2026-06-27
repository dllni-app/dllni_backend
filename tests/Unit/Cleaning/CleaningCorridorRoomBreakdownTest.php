<?php

declare(strict_types=1);

use Modules\Cleaning\Support\WorkerRoomAssignmentPlanner;
use Modules\User\Services\UserCleaningOrderEstimationService;

it('normalizes corridor from room size breakdown and keeps it for room planning', function (): void {
    $service = app(UserCleaningOrderEstimationService::class);

    $details = $service->normalizePropertyDetailsForStorage('villa', [
        'room_size_breakdown' => [
            'bedroom' => ['large' => 1],
            'corridor' => ['large' => 1],
        ],
    ]);

    expect($details['rooms'])->toBe(1);
    expect($details['bedrooms'])->toBe(2);
    expect($details['room_size_breakdown']['corridor']['small'])->toBe(0);
    expect($details['room_size_breakdown']['corridor']['large'])->toBe(1);
});

it('derives corridor room keys for worker assignments', function (): void {
    $plan = WorkerRoomAssignmentPlanner::plan(
        [
            'room_size_breakdown' => [
                'bedroom' => ['large' => 1],
                'corridor' => ['large' => 1],
            ],
        ],
        [
            [
                'workerSlot' => 1,
                'preferredWorkerId' => 10,
                'rooms' => [
                    [
                        'roomKey' => 'bedroom.large.1',
                        'roomType' => 'bedroom',
                        'roomSize' => 'large',
                    ],
                    [
                        'roomKey' => 'corridor.large.1',
                        'roomType' => 'corridor',
                        'roomSize' => 'large',
                    ],
                ],
            ],
        ],
        'preferred_worker',
        1,
        10,
    );

    expect($plan['errors'])->toBe([]);
    expect(array_column($plan['derivedRooms'], 'room_key'))->toContain('corridor.large.1');
    expect($plan['roomPlans']['corridor.large.1']['workerSlot'])->toBe(1);
});

it('normalizes Flutter preferred-worker room entries into one worker slot', function (): void {
    $plan = WorkerRoomAssignmentPlanner::plan(
        [
            'room_size_breakdown' => [
                'bedroom' => ['large' => 2],
                'bathroom' => ['medium' => 1],
            ],
        ],
        [
            [
                'workerSlot' => 1,
                'preferredWorkerId' => null,
                'rooms' => [
                    [
                        'roomKey' => 'bedroom.large.1',
                        'roomType' => 'bedroom',
                        'roomSize' => 'large',
                    ],
                ],
            ],
            [
                'workerSlot' => 2,
                'preferredWorkerId' => null,
                'rooms' => [
                    [
                        'roomKey' => 'bedroom.large.2',
                        'roomType' => 'bedroom',
                        'roomSize' => 'large',
                    ],
                ],
            ],
            [
                'workerSlot' => 3,
                'preferredWorkerId' => null,
                'rooms' => [
                    [
                        'roomKey' => 'bathroom.medium.1',
                        'roomType' => 'bathroom',
                        'roomSize' => 'medium',
                    ],
                ],
            ],
        ],
        'preferred_worker',
        1,
        10,
    );

    expect($plan['errors'])->toBe([]);
    expect($plan['assignments'])->toHaveCount(1);
    expect($plan['assignments'][0]['workerSlot'])->toBe(1);
    expect($plan['assignments'][0]['preferredWorkerId'])->toBe(10);
    expect($plan['assignments'][0]['rooms'])->toHaveCount(3);
    expect($plan['roomPlans']['bedroom.large.2'])->toMatchArray([
        'workerSlot' => 1,
        'preferredWorkerId' => 10,
    ]);
});
