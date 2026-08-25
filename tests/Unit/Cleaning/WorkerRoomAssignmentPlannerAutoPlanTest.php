<?php

declare(strict_types=1);

use Modules\Cleaning\Support\WorkerRoomAssignmentPlanner;

it('auto plans rooms across all worker slots when explicit assignments are omitted', function (): void {
    $plan = WorkerRoomAssignmentPlanner::plan(
        [
            'room_size_breakdown' => [
                'bedroom' => ['small' => 3],
            ],
        ],
        null,
        'open_count',
        3,
        null,
    );

    expect($plan['errors'])->toBe([])
        ->and($plan['assignments'])->toHaveCount(3)
        ->and($plan['roomPlans'])->toHaveCount(3)
        ->and(array_column($plan['assignments'], 'workerSlot'))->toBe([1, 2, 3]);

    foreach ($plan['assignments'] as $assignment) {
        expect($assignment['rooms'])->toHaveCount(1)
            ->and($assignment['roomsWeight'])->toBe(1.0);
    }

    expect(array_values(array_map(
        static fn (array $roomPlan): int => $roomPlan['workerSlot'],
        $plan['roomPlans'],
    )))->toBe([1, 2, 3]);
});
