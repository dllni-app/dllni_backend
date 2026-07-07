<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Enums\WorkerPreferredWorkType;
use App\Filament\Resources\CleaningWorkers\Pages\CreateCleaningWorker;
use App\Filament\Resources\CleaningWorkers\Pages\EditCleaningWorker;
use App\Models\User;
use App\Models\Worker;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $adminUser = User::factory()->create(['email' => 'cleaning-workers-admin@example.com']);
    $adminUser->assignRole('admin');
    $this->actingAs($adminUser);
});

it('creates a cleaning worker and syncs the linked user account', function (): void {
    $linkedUser = User::factory()->create([
        'name' => 'Old Name',
        'phone' => '+963911111111',
        'module_type' => null,
    ]);

    Livewire::test(CreateCleaningWorker::class)
        ->fillForm([
            'first_name' => 'Maher',
            'preferred_work_type' => WorkerPreferredWorkType::Both->value,
            'user_phone' => '+963911111111',
            'user_password' => 'password',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $worker = Worker::query()->where('user_id', $linkedUser->id)->firstOrFail();
    expect($worker->first_name)->toBe('Maher');

    $linkedUser->refresh();
    expect($linkedUser->name)->toBe('Maher')
        ->and($linkedUser->phone)->toBe('+963911111111')
        ->and($linkedUser->module_type)->toBe(UserModuleType::CleaningWorker);
});

it('rejects cleaning worker account phones outside the Syrian +963 mobile format', function (): void {
    Livewire::test(CreateCleaningWorker::class)
        ->fillForm([
            'first_name' => 'Maher',
            'preferred_work_type' => WorkerPreferredWorkType::Both->value,
            'user_phone' => '0911111111',
            'user_password' => 'password',
        ])
        ->call('create')
        ->assertHasFormErrors(['user_phone']);
});

it('rejects creating a second cleaning worker for an existing worker account phone', function (): void {
    $linkedUser = User::factory()->create([
        'name' => 'Sara',
        'phone' => '+963933333333',
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);
    Worker::factory()->create([
        'user_id' => $linkedUser->id,
        'first_name' => 'Sara',
    ]);

    Livewire::test(CreateCleaningWorker::class)
        ->fillForm([
            'first_name' => 'Sara Duplicate',
            'preferred_work_type' => WorkerPreferredWorkType::Both->value,
            'user_phone' => '+963933333333',
            'user_password' => 'password',
        ])
        ->call('create')
        ->assertHasFormErrors(['user_phone']);
});

it('updates a cleaning worker without writing user fields to the workers table', function (): void {
    $linkedUser = User::factory()->create([
        'name' => 'Sara',
        'phone' => '+963900000000',
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);
    $worker = Worker::factory()->create([
        'user_id' => $linkedUser->id,
        'first_name' => 'Sara',
        'is_active' => true,
    ]);

    Livewire::test(EditCleaningWorker::class, ['record' => $worker->getRouteKey()])
        ->fillForm([
            'is_active' => false,
            'user_phone' => '+963922222222',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $worker->refresh();
    expect($worker->is_active)->toBeFalse();

    $linkedUser->refresh();
    expect($linkedUser->phone)->toBe('+963922222222')
        ->and($linkedUser->module_type)->toBe(UserModuleType::CleaningWorker);
});
