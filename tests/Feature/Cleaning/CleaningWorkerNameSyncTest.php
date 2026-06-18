<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use App\Models\Worker;

it('syncs the worker first name when the cleaning worker user name changes', function (): void {
    $user = User::factory()->create([
        'name' => 'Cleaning Worker',
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);

    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'first_name' => 'Cleaning',
    ]);

    $user->forceFill(['name' => 'Ali Hassan'])->save();

    expect($worker->refresh()->first_name)->toBe('Ali Hassan');
});

it('syncs the cleaning worker user name when the worker first name changes', function (): void {
    $user = User::factory()->create([
        'name' => 'Cleaning Worker',
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);

    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'first_name' => 'Cleaning',
    ]);

    $worker->forceFill(['first_name' => 'Maher Ali'])->save();

    expect($user->refresh()->name)->toBe('Maher Ali');
});
