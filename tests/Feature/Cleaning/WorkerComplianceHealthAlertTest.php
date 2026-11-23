<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cleaning\Services\WorkerComplianceHealthAlertService;
use Spatie\Permission\Models\Permission;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    config()->set('cleaning.worker_compliance.enabled', true);
    config()->set('cleaning.worker_compliance.notifications_enabled', true);
});

function enrolledPilotSession(bool $activeSession = true): Worker
{
    $worker = Worker::factory()->create();
    DB::table('worker_compliance_states')->insert([
        'worker_id' => $worker->id,
        'is_enrolled' => true,
        'epoch' => $activeSession ? (string) Str::uuid() : null,
        'last_contact_at' => now()->subHour(),
        'last_reported_permission_state' => 'always',
        'last_reported_location_services_enabled' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $worker;
}

it('notifies authorized active staff only once about a continuing location outage', function (): void {
    $reviewer = User::factory()->create(['is_active' => true, 'module_type' => null]);
    $reviewer->givePermissionTo(Permission::findOrCreate('cleaning.worker_compliance.overview.view', 'web'));
    $unauthorized = User::factory()->create(['is_active' => true, 'module_type' => null]);
    $worker = enrolledPilotSession();

    $service = app(WorkerComplianceHealthAlertService::class);
    expect($service->notifyIfNeeded())->toBe(1);
    expect($service->notifyIfNeeded())->toBe(0);

    $notifications = DB::table('notifications')->get();
    expect($notifications)->toHaveCount(1)
        ->and((int) $notifications->first()->notifiable_id)->toBe($reviewer->id)
        ->and($notifications->first()->data)->not->toContain('"worker_id"')
        ->and($notifications->first()->data)->not->toContain('latitude')
        ->and($notifications->first()->data)->not->toContain('longitude');

    expect(DB::table('notifications')->where('notifiable_id', $unauthorized->id)->count())->toBe(0);
});

it('does not notify for declined, disabled, or recovered telemetry', function (): void {
    enrolledPilotSession(activeSession: false);
    $service = app(WorkerComplianceHealthAlertService::class);

    expect($service->notifyIfNeeded())->toBe(0);
    config()->set('cleaning.worker_compliance.notifications_enabled', false);
    expect($service->notifyIfNeeded())->toBe(0);
});
