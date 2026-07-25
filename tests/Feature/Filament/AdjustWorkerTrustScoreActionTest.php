<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Filament\Resources\CleaningWorkers\Pages\EditCleaningWorker;
use App\Filament\Resources\CleaningWorkers\Pages\ViewCleaningWorker;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerTrustLog;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app()->setLocale('ar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin);
});

it('adjusts trust score from the cleaning worker view action and logs the change', function (): void {
    $workerUser = User::factory()->create([
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'trust_score' => 80,
    ]);

    Livewire::test(ViewCleaningWorker::class, ['record' => $worker->getRouteKey()])
        ->assertActionExists('adjustTrustScore')
        ->callAction('adjustTrustScore', data: [
            'trust_score' => 65,
            'reason' => 'مراجعة إدارية',
        ])
        ->assertHasNoActionErrors();

    expect((int) $worker->fresh()->trust_score)->toBe(65);

    $log = WorkerTrustLog::query()
        ->where('worker_id', $worker->id)
        ->latest('id')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->score_before)->toBe(80)
        ->and($log->score_after)->toBe(65)
        ->and($log->score_delta)->toBe(-15)
        ->and($log->reason)->toContain('admin_manual_adjustment')
        ->and($log->reason)->toContain('مراجعة إدارية');
});

it('exposes the trust adjustment action on the cleaning worker edit page', function (): void {
    $workerUser = User::factory()->create([
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'trust_score' => 70,
    ]);

    Livewire::test(EditCleaningWorker::class, ['record' => $worker->getRouteKey()])
        ->assertActionExists('adjustTrustScore')
        ->callAction('adjustTrustScore', data: [
            'trust_score' => 90,
            'reason' => '',
        ])
        ->assertHasNoActionErrors();

    expect((int) $worker->fresh()->trust_score)->toBe(90)
        ->and(
            WorkerTrustLog::query()
                ->where('worker_id', $worker->id)
                ->where('reason', 'admin_manual_adjustment')
                ->where('score_after', 90)
                ->exists()
        )->toBeTrue();
});
