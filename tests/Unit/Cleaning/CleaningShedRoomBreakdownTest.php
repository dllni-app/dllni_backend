<?php

declare(strict_types=1);

use Modules\Cleaning\Support\WorkerRoomAssignmentPlanner;
use Modules\User\Services\UserCleaningOrderEstimationService;

it('normalizes shed from room size breakdown and keeps it for room planning', function (): void {
    $service = app(UserCleaningOrderEstimationService::class);

    $details = $service->normalizePropertyDetailsForStorage('villa', [
        'room_size_breakdown' => [
            'bedroom' => ['large' => 1],
            'shed' => ['medium' => 1],
        ],
    ]);

    expect($details['rooms'])->toBe(1);
    expect($details['sheds'])->toBe(1);
    expect($details['room_size_breakdown']['shed']['small'])->toBe(0);
    expect($details['room_size_breakdown']['shed']['medium'])->toBe(1);
});

it('derives shed room keys for worker assignments', function (): void {
    $plan = WorkerRoomAssignmentPlanner::plan(
        [
            'room_size_breakdown' => [
                'bedroom' => ['large' => 1],
                'shed' => ['medium' => 1],
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
                        'roomKey' => 'shed.medium.1',
                        'roomType' => 'shed',
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
    expect(array_column($plan['derivedRooms'], 'room_key'))->toContain('shed.medium.1');
    expect($plan['roomPlans']['shed.medium.1']['workerSlot'])->toBe(1);
});
