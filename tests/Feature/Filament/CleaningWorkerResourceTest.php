<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Enums\WorkerPreferredWorkType;
use App\Filament\Resources\CleaningWorkers\Pages\CreateCleaningWorker;
use App\Filament\Resources\CleaningWorkers\Pages\EditCleaningWorker;
use App\Filament\Resources\CleaningWorkers\Pages\ListCleaningWorkers;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerTrustLog;
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

it('creates a cleaning worker with an individual debt limit and syncs the linked user account', function (): void {
    $linkedUser = User::factory()->create([
        'name' => 'Old Name',
        'phone' => '+963911111111',
        'module_type' => null,
    ]);

    Livewire::test(CreateCleaningWorker::class)
        ->fillForm([
            'first_name' => 'Maher',
            'preferred_work_type' => WorkerPreferredWorkType::Both->value,
            'worker_debt_limit' => 750,
            'user_phone' => '+963911111111',
            'user_password' => 'password',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $worker = Worker::query()->where('user_id', $linkedUser->id)->firstOrFail();
    expect($worker->first_name)->toBe('Maher');

    $deposit = CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->firstOrFail();
    expect((float) $deposit->max_negative_balance)->toBe(750.0);

    $linkedUser->refresh();
    expect($linkedUser->name)->toBe('Maher')
        ->and($linkedUser->phone)->toBe('+963911111111')
        ->and($linkedUser->module_type)->toBe(UserModuleType::CleaningWorker);
});

it('shows required validation errors for an empty cleaning worker form', function (): void {
    Livewire::test(CreateCleaningWorker::class)
        ->fillForm([])
        ->call('create')
        ->assertHasFormErrors([
            'first_name',
            'user_phone',
            'user_password',
        ]);
});

it('rejects cleaning worker account phones outside the Syrian +963 mobile format', function (): void {
    Livewire::test(CreateCleaningWorker::class)
        ->fillForm([
            'first_name' => 'Maher',
            'preferred_work_type' => WorkerPreferredWorkType::Both->value,
            'worker_debt_limit' => 0,
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
            'worker_debt_limit' => 0,
            'user_phone' => '+963933333333',
            'user_password' => 'password',
        ])
        ->call('create')
        ->assertHasFormErrors(['user_phone']);
});

it('updates trust score home location and the individual debt limit without writing user fields to the workers table', function (): void {
    $linkedUser = User::factory()->create([
        'name' => 'Sara',
        'phone' => '+963900000000',
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);
    $worker = Worker::factory()->create([
        'user_id' => $linkedUser->id,
        'first_name' => 'Sara',
        'gender' => 'male',
        'trust_score' => 100,
        'home_address' => null,
        'home_latitude' => null,
        'home_longitude' => null,
    ]);
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 0,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100,
        'is_active' => true,
    ]);

    Livewire::test(EditCleaningWorker::class, ['record' => $worker->getRouteKey()])
        ->fillForm([
            'gender' => 'female',
            'trust_score' => 84,
            'worker_debt_limit' => 900,
            'home_address' => 'Damascus - Al Mazzeh',
            'home_latitude' => 33.5038,
            'home_longitude' => 36.2504,
            'user_phone' => '+963922222222',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $worker->refresh();
    expect($worker->gender)->toBe('female')
        ->and($worker->trust_score)->toBe(84)
        ->and($worker->home_address)->toBe('Damascus - Al Mazzeh')
        ->and((float) $worker->home_latitude)->toBe(33.5038)
        ->and((float) $worker->home_longitude)->toBe(36.2504)
        ->and((float) $worker->deposit()->value('max_negative_balance'))->toBe(900.0);

    $linkedUser->refresh();
    expect($linkedUser->phone)->toBe('+963922222222')
        ->and($linkedUser->module_type)->toBe(UserModuleType::CleaningWorker);

    $trustLog = WorkerTrustLog::query()->where('worker_id', $worker->id)->latest('id')->firstOrFail();
    expect($trustLog->reason)->toBe('admin_manual_adjustment')
        ->and($trustLog->score_before)->toBe(100)
        ->and($trustLog->score_after)->toBe(84)
        ->and($trustLog->score_delta)->toBe(-16);
});

it('filters the cleaning worker table by gender', function (): void {
    $maleUser = User::factory()->create([
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);
    $femaleUser = User::factory()->create([
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);

    $maleWorker = Worker::factory()->create([
        'user_id' => $maleUser->id,
        'gender' => 'male',
    ]);
    $femaleWorker = Worker::factory()->create([
        'user_id' => $femaleUser->id,
        'gender' => 'female',
    ]);

    Livewire::test(ListCleaningWorkers::class)
        ->filterTable('gender', 'female')
        ->assertCanSeeTableRecords([$femaleWorker])
        ->assertCanNotSeeTableRecords([$maleWorker])
        ->assertTableColumnStateSet('gender', 'female', record: $femaleWorker);
});
