<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Cleaning\Services\WorkerCompliancePilotEnrollmentService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function pilotManager(bool $authorized): User
{
    $user = User::factory()->create();
    $user->assignRole(Role::findOrCreate('Cleaning Pilot Manager', 'web'));

    if ($authorized) {
        $user->givePermissionTo(Permission::findOrCreate('cleaning.worker_compliance.configure', 'web'));
    }

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    return $user->fresh();
}

it('rejects enrollment changes from anyone without dedicated permission', function (): void {
    $worker = Worker::factory()->create();
    $unauthorized = pilotManager(false);

    expect(fn () => app(WorkerCompliancePilotEnrollmentService::class)
        ->setEnrollment($worker, $unauthorized, true))
        ->toThrow(HttpException::class);

    $this->assertDatabaseMissing('worker_compliance_states', ['worker_id' => $worker->id]);
});

it('offers the pilot without collecting location, and revokes an existing session on removal', function (): void {
    $worker = Worker::factory()->create(['is_active' => true, 'is_suspended' => false]);
    $manager = pilotManager(true);
    $service = app(WorkerCompliancePilotEnrollmentService::class);

    $service->setEnrollment($worker, $manager, true);
    $this->assertDatabaseHas('worker_compliance_states', [
        'worker_id' => $worker->id,
        'is_enrolled' => true,
        'epoch' => null,
    ]);

    $epoch = (string) Str::uuid();
    DB::table('worker_compliance_states')->where('worker_id', $worker->id)->update([
        'epoch' => $epoch,
        'access_token_hash' => hash('sha256', 'test-access'),
        'lease_expires_at' => now()->addHour(),
        'revoked_at' => null,
    ]);
    $service->setEnrollment($worker, $manager, true);

    // An idempotent re-enrollment must not invalidate existing sessions.
    expect(DB::table('worker_compliance_states')->where('worker_id', $worker->id)->value('epoch'))->toBe($epoch);

    $service->setEnrollment($worker, $manager, false);
    $this->assertDatabaseHas('worker_compliance_states', [
        'worker_id' => $worker->id,
        'is_enrolled' => false,
        'epoch' => null,
        'access_token_hash' => null,
        'lease_expires_at' => null,
    ]);
    expect($service->isEnrolled($worker))->toBeFalse();
});

it('does not offer tracking to a suspended worker', function (): void {
    $worker = Worker::factory()->create(['is_active' => true, 'is_suspended' => true]);
    $manager = pilotManager(true);

    expect(fn () => app(WorkerCompliancePilotEnrollmentService::class)
        ->setEnrollment($worker, $manager, true))
        ->toThrow(Illuminate\Validation\ValidationException::class);
});
