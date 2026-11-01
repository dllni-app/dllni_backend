<?php

declare(strict_types=1);

use Modules\Cleaning\Support\CleaningRoomBlueprints;
use Modules\User\Services\UserCleaningOrderEstimationService;

it('normalizes sheds in the booked room breakdown', function (): void {
    $details = app(UserCleaningOrderEstimationService::class)
        ->normalizePropertyDetailsForStorage('villa', [
            'room_size_breakdown' => [
                'bedroom' => ['large' => 1],
                'shed' => ['medium' => 1],
            ],
        ]);

    expect($details['room_size_breakdown']['shed']['medium'])->toBe(1)
        ->and($details['room_size_breakdown']['shed']['small'])->toBe(0);
});

it('generates shed task descriptions without assigning a worker', function (): void {
    $rooms = CleaningRoomBlueprints::generateRoomBlueprints([
        'room_size_breakdown' => [
            'bedroom' => ['large' => 1],
            'shed' => ['medium' => 1],
        ],
    ]);

    expect(array_column($rooms, 'room_key'))->toContain('shed.medium.1');
    foreach ($rooms as $room) {
        expect($room)->not->toHaveKey('workerSlot')
            ->not->toHaveKey('assignedWorkerId');
    }
});
