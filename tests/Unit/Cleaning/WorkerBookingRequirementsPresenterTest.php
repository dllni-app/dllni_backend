<?php

declare(strict_types=1);

use Modules\Cleaning\Support\WorkerBookingRequirementsPresenter;

it('shows offer-safe grouped room counts, sizes and cleaning modes', function () {
    $requirements = WorkerBookingRequirementsPresenter::summarize([
        ['id' => 18, 'roomType' => 'bedroom', 'roomTypeLabel' => 'غرفة نوم', 'roomSize' => 'medium', 'roomSizeLabel' => 'متوسطة', 'cleaningMode' => 'deep', 'assignedWorkerId' => 12],
        ['id' => 19, 'roomType' => 'bedroom', 'roomTypeLabel' => 'غرفة نوم', 'roomSize' => 'medium', 'roomSizeLabel' => 'متوسطة', 'cleaningMode' => 'deep', 'assignedWorkerId' => 13],
        ['id' => 20, 'roomType' => 'bedroom', 'roomSize' => 'small', 'cleaningMode' => 'regular'],
    ]);

    expect($requirements)->toHaveCount(2)
        ->and($requirements[0]['count'])->toBe(2)
        ->and($requirements[0]['cleaningModeLabel'])->toBe('تعزيل')
        ->and($requirements[0]['roomSizeLabel'])->toBe('متوسطة')
        ->and($requirements[1]['count'])->toBe(1)
        ->and($requirements[1]['cleaningModeLabel'])->toBe('حوسة')
        ->and($requirements[0])->not->toHaveKey('id')
        ->and($requirements[0])->not->toHaveKey('assignedWorkerId');
});

it('recovers legacy customer room counts without inventing missing sizes', function () {
    $requirements = WorkerBookingRequirementsPresenter::summarize([], [
        'bedrooms' => 2,
        'bathrooms' => 1,
        'kitchens' => 1,
        'living_room_size' => 'medium',
    ]);

    expect($requirements)->toHaveCount(4)
        ->and($requirements[0]['roomType'])->toBe('bedroom')
        ->and($requirements[0]['count'])->toBe(2)
        ->and($requirements[0]['roomSize'])->toBeNull()
        ->and($requirements[1]['roomType'])->toBe('bathroom')
        ->and($requirements[2]['roomType'])->toBe('kitchen')
        ->and($requirements[3]['roomType'])->toBe('living_room')
        ->and($requirements[3]['roomSize'])->toBe('medium');
});
