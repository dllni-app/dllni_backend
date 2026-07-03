<?php

declare(strict_types=1);

use App\Filament\Pages\GeographicCoverage;
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

    $adminUser = User::factory()->create(['email' => 'geo-coverage-admin@example.com']);
    $adminUser->assignRole('admin');
    $this->actingAs($adminUser);
});

it('renders the geographic coverage page as a simple neighborhoods table with worker count and coverage', function (): void {
    $covered = CleaningNeighborhood::factory()->create(['name_ar' => 'حي مغطى']);
    $uncovered = CleaningNeighborhood::factory()->create(['name_ar' => 'حي بدون عمال']);

    $workers = Worker::factory()->count(8)->create();
    foreach ($workers as $worker) {
        WorkerZone::query()->create([
            'worker_id' => $worker->id,
            'neighborhood_id' => $covered->id,
            'name' => 'zone',
            'is_active' => true,
        ]);
    }

    Livewire::test(GeographicCoverage::class)
        ->assertCanSeeTableRecords([$covered, $uncovered])
        ->assertTableColumnStateSet('workers_count', 8, record: $covered)
        ->assertTableColumnStateSet('coverage_level', 'high', record: $covered)
        ->assertTableColumnStateSet('workers_count', 0, record: $uncovered)
        ->assertTableColumnStateSet('coverage_level', 'low', record: $uncovered);
});
