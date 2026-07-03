<?php

declare(strict_types=1);

use App\Filament\Resources\CleaningNeighborhoods\Pages\ListCleaningNeighborhoods;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerZone;
use Livewire\Livewire;
use Modules\Cleaning\Models\CleaningNeighborhood;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $adminUser = User::factory()->create(['email' => 'neighborhoods-coverage-admin@example.com']);
    $adminUser->assignRole('admin');
    $this->actingAs($adminUser);
});

it('shows worker count and high/low coverage per neighborhood', function (): void {
    $wellCovered = CleaningNeighborhood::factory()->create(['name_ar' => 'حي مغطى']);
    $poorlyCovered = CleaningNeighborhood::factory()->create(['name_ar' => 'حي ضعيف']);

    $workers = Worker::factory()->count(8)->create();
    foreach ($workers as $worker) {
        WorkerZone::query()->create([
            'worker_id' => $worker->id,
            'neighborhood_id' => $wellCovered->id,
            'name' => 'zone',
            'is_active' => true,
        ]);
    }

    WorkerZone::query()->create([
        'worker_id' => $workers->first()->id,
        'neighborhood_id' => $poorlyCovered->id,
        'name' => 'zone',
        'is_active' => true,
    ]);

    Livewire::test(ListCleaningNeighborhoods::class)
        ->assertCanSeeTableRecords([$wellCovered, $poorlyCovered])
        ->assertTableColumnStateSet('workers_count', 8, record: $wellCovered)
        ->assertTableColumnStateSet('workers_count', 1, record: $poorlyCovered)
        ->assertTableColumnStateSet('coverage_level', 'high', record: $wellCovered)
        ->assertTableColumnStateSet('coverage_level', 'low', record: $poorlyCovered);
});
