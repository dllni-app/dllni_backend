<?php

declare(strict_types=1);

use App\Enums\WorkerHomeLocationStatus;
use App\Models\User;
use App\Models\Worker;

it('approves pending home location onto approved fields', function (): void {
    $worker = Worker::factory()->create([
        'home_address' => 'Old Home',
        'home_latitude' => 33.40000000,
        'home_longitude' => 36.20000000,
        'pending_home_address' => 'New Home',
        'pending_home_latitude' => 33.51380000,
        'pending_home_longitude' => 36.27650000,
        'home_location_status' => WorkerHomeLocationStatus::Pending,
        'home_location_rejection_reason' => null,
    ]);

    $worker->approvePendingHomeLocation();
    $worker->refresh();

    expect($worker->home_address)->toBe('New Home')
        ->and((float) $worker->home_latitude)->toBe(33.5138)
        ->and((float) $worker->home_longitude)->toBe(36.2765)
        ->and($worker->pending_home_address)->toBeNull()
        ->and($worker->pending_home_latitude)->toBeNull()
        ->and($worker->pending_home_longitude)->toBeNull()
        ->and($worker->home_location_status)->toBe(WorkerHomeLocationStatus::Approved)
        ->and($worker->home_location_rejection_reason)->toBeNull();
});

it('rejects pending home location and stores the reason', function (): void {
    $worker = Worker::factory()->create([
        'home_address' => 'Old Home',
        'home_latitude' => 33.40000000,
        'home_longitude' => 36.20000000,
        'pending_home_address' => 'Rejected Home',
        'pending_home_latitude' => 33.60000000,
        'pending_home_longitude' => 36.30000000,
        'home_location_status' => WorkerHomeLocationStatus::Pending,
    ]);

    $worker->rejectPendingHomeLocation('الموقع غير واضح');
    $worker->refresh();

    expect($worker->home_address)->toBe('Old Home')
        ->and($worker->pending_home_address)->toBeNull()
        ->and($worker->pending_home_latitude)->toBeNull()
        ->and($worker->pending_home_longitude)->toBeNull()
        ->and($worker->home_location_status)->toBe(WorkerHomeLocationStatus::Rejected)
        ->and($worker->home_location_rejection_reason)->toBe('الموقع غير واضح');
});

it('does not queue pending home location when values are unchanged', function (): void {
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'is_active' => true,
        'home_address' => 'Same Home',
        'home_latitude' => 33.40000000,
        'home_longitude' => 36.20000000,
        'home_location_status' => WorkerHomeLocationStatus::Approved,
    ]);

    $updates = $worker->pendingHomeLocationUpdatesFrom([
        'homeAddress' => 'Same Home',
        'homeLatitude' => 33.40000000,
        'homeLongitude' => 36.20000000,
    ]);

    expect($updates)->toBe([]);
});
