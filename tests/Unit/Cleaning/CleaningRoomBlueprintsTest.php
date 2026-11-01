<?php

declare(strict_types=1);

use Modules\Cleaning\Support\CleaningRoomBlueprints;

it('describes the booked rooms without generating any worker slot or ownership', function (): void {
    $rooms = CleaningRoomBlueprints::generateRoomBlueprints([
        'room_size_breakdown' => [
            'bedroom' => ['small' => 3],
        ],
    ]);

    expect($rooms)->toHaveCount(3);
    expect(array_column($rooms, 'room_key'))->toBe([
        'bedroom.small.1', 'bedroom.small.2', 'bedroom.small.3',
    ]);
    foreach ($rooms as $room) {
        expect($room)->not->toHaveKey('workerSlot')
            ->not->toHaveKey('preferredWorkerId')
            ->not->toHaveKey('assigned_worker_id')
            ->not->toHaveKey('assignment_source');
    }
});
