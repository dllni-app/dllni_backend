<?php

declare(strict_types=1);

use Modules\Cleaning\Support\CleaningRoomBlueprints;
use Modules\User\Services\UserCleaningOrderEstimationService;

it('normalizes corridors in the booked room breakdown', function (): void {
    $details = app(UserCleaningOrderEstimationService::class)
        ->normalizePropertyDetailsForStorage('villa', [
            'room_size_breakdown' => [
                'bedroom' => ['large' => 1],
                'corridor' => ['large' => 1],
            ],
        ]);

    expect($details['room_size_breakdown']['corridor']['large'])->toBe(1)
        ->and($details['room_size_breakdown']['corridor']['small'])->toBe(0);
});

it('generates corridor task descriptions without assigning a worker', function (): void {
    $rooms = CleaningRoomBlueprints::generateRoomBlueprints([
        'room_size_breakdown' => [
            'bedroom' => ['large' => 1],
            'corridor' => ['large' => 1],
        ],
    ]);

    expect(array_column($rooms, 'room_key'))->toContain('corridor.large.1');
    foreach ($rooms as $room) {
        expect($room)->not->toHaveKey('workerSlot')
            ->not->toHaveKey('assignedWorkerId');
    }
});
