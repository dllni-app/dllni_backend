<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use App\Models\Worker;
use Database\Seeders\CleaningWorkersSeeder;
use Illuminate\Support\Facades\Hash;

beforeEach(function (): void {
    $this->seed(CleaningWorkersSeeder::class);
});

it('creates three cleaning worker users with linked worker records', function (): void {
    $expectedWorkers = [
        ['email' => 'cleaning.worker@dllni.sy', 'phone' => '+963944100001', 'first_name' => 'Cleaning'],
        ['email' => 'cleaning.worker2@dllni.sy', 'phone' => '+963944100004', 'first_name' => 'Lina'],
        ['email' => 'cleaning.worker3@dllni.sy', 'phone' => '+963944100005', 'first_name' => 'Omar'],
    ];

    foreach ($expectedWorkers as $expected) {
        $user = User::where('email', $expected['email'])->first();

        expect($user)->not->toBeNull();
        expect($user->phone)->toBe($expected['phone']);
        expect($user->module_type)->toBe(UserModuleType::CleaningWorker);
        expect(Hash::check('password', $user->password))->toBeTrue();

        $worker = Worker::where('user_id', $user->id)->first();
        expect($worker)->not->toBeNull();
        expect($worker->first_name)->toBe($expected['first_name']);
        expect($worker->is_active)->toBeTrue();
        expect($worker->is_verified)->toBeTrue();
    }
});

it('is idempotent when run twice', function (): void {
    $this->seed(CleaningWorkersSeeder::class);

    expect(User::where('email', 'cleaning.worker@dllni.sy')->count())->toBe(1);
    expect(User::where('email', 'cleaning.worker2@dllni.sy')->count())->toBe(1);
    expect(User::where('email', 'cleaning.worker3@dllni.sy')->count())->toBe(1);
});
