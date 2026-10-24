<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Services\DepositService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

function cleaningDepositAdminActor(string $role = 'admin', bool $active = true): User
{
    Role::findOrCreate($role, 'web');
    $user = User::factory()->create(['is_active' => $active]);
    $user->assignRole($role);

    return $user;
}

function cleaningDepositAdminRoutes(string $workerId): array
{
    return [
        ['GET', '/api/v1/admin/cleaning/deposits/settings', null],
        ['PUT', '/api/v1/admin/cleaning/deposits/settings', ['minimum_deposit_amount' => 25]],
        ['POST', "/api/v1/admin/cleaning/deposits/{$workerId}/deposit", ['amount' => 25]],
        ['POST', "/api/v1/admin/cleaning/deposits/{$workerId}/debt", ['amount' => 25]],
        ['PUT', "/api/v1/admin/cleaning/deposits/{$workerId}/allowance-limit", ['amount' => 25]],
        ['POST', "/api/v1/admin/cleaning/deposits/{$workerId}/settle-full-debt", []],
        ['POST', "/api/v1/admin/cleaning/deposits/{$workerId}/refund", []],
        ['POST', "/api/v1/admin/cleaning/deposits/{$workerId}/withdraw", []],
        ['GET', "/api/v1/admin/cleaning/deposits/{$workerId}/transactions", null],
    ];
}

function requestCleaningDepositAdminRoute(object $test, array $route)
{
    [$method, $uri, $payload] = $route;

    return match ($method) {
        'GET' => $test->getJson($uri),
        'PUT' => $test->putJson($uri, $payload),
        'POST' => $test->postJson($uri, $payload),
        default => throw new InvalidArgumentException("Unsupported route method [{$method}]."),
    };
}

function cleaningDepositAdminFinancialSnapshot(?Worker $worker = null): array
{
    $tableNames = Schema::getTableListing();
    $relevantTables = array_filter($tableNames, static fn (string $table): bool => str_contains($table, 'cleaning')
        && (str_contains($table, 'deposit') || str_contains($table, 'financial') || str_contains($table, 'setting') || str_contains($table, 'activity'))
        || in_array($table, ['activity_log'], true)
    );
    sort($relevantTables);

    $counts = array_combine(
        $relevantTables,
        array_map(static fn (string $table): int => DB::table($table)->count(), $relevantTables),
    ) ?: [];

    return [
        'counts' => $counts,
        'settings' => CleaningDepositSetting::query()->first()?->getAttributes(),
        'account' => $worker === null
            ? null
            : CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->first()?->getAttributes(),
        'transactions' => $worker === null
            ? []
            : CleaningDepositTransaction::query()
                ->where('worker_id', $worker->id)
                ->orderBy('id')
                ->get()
                ->map(static fn (CleaningDepositTransaction $transaction): array => $transaction->getAttributes())
                ->all(),
        'worker' => $worker?->only(['is_active', 'is_suspended', 'security_deposit_status']),
        'eligible' => $worker === null
            ? null
            : app(DepositService::class)->isWorkerEligibleForNewRequests($worker->fresh(['deposit'])),
    ];
}

function cleaningDepositAdminWorker(): Worker
{
    return Worker::factory()->create([
        'trust_score' => 90,
        'is_active' => true,
        'is_suspended' => false,
        'security_deposit_status' => 'active',
    ]);
}

function cleaningDepositAdminAccount(Worker $worker, float $balance, float $debt = 0): CleaningWorkerDeposit
{
    return CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => $balance,
        'debt_balance' => $debt,
        'deposited_total' => $balance,
        'withdrawn_total' => 0,
        'admin_revenue_withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100,
        'is_active' => true,
    ]);
}

it('denies a roleless active user reading deposit settings', function (): void {
    $actor = User::factory()->create(['is_active' => true]);
    expect($actor->getRoleNames())->toBeEmpty();
    Sanctum::actingAs($actor);

    $response = $this->getJson('/api/v1/admin/cleaning/deposits/settings');
    $response->assertForbidden();
});

it('denies a roleless active user recording a worker deposit', function (): void {
    config(['cleaning_transaction_admission.admin_deposit_pilot_enabled' => false]);
    $actor = User::factory()->create(['is_active' => true]);
    $worker = cleaningDepositAdminWorker();
    expect($actor->getRoleNames())->toBeEmpty();
    Sanctum::actingAs($actor);

    $response = $this->postJson("/api/v1/admin/cleaning/deposits/{$worker->id}/deposit", [
        'amount' => 25,
        'reference' => 't02_auth_roleless_before_patch',
    ]);
    $accountCount = CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->count();
    $transactionCount = CleaningDepositTransaction::query()->where('worker_id', $worker->id)->count();
    $response->assertForbidden();
    expect($accountCount)->toBe(0)->and($transactionCount)->toBe(0);
});

it('returns unauthenticated before binding any of the nine admin deposit routes', function (): void {
    foreach (cleaningDepositAdminRoutes('missing-worker-id') as $route) {
        requestCleaningDepositAdminRoute($this, $route)->assertUnauthorized();
    }
});

it('denies every non-admin actor before worker binding with both deposit pilot states', function (): void {
    $prohibitedRoles = [
        'Cleaning Ops Manager',
        'Accountant',
        'Customer Support',
        'Cleaning Supervisor',
        'Onboarding Specialist',
    ];
    $actors = [
        'roleless customer' => static fn (): User => User::factory()->create(['is_active' => true]),
        'inactive admin' => static fn (): User => cleaningDepositAdminActor(active: false),
        'inactive super admin' => static fn (): User => cleaningDepositAdminActor('Super Admin', active: false),
        'worker principal' => static function (): User {
            $user = User::factory()->create(['is_active' => true, 'module_type' => UserModuleType::CleaningWorker]);
            Worker::factory()->create(['user_id' => $user->id]);

            return $user;
        },
    ];

    foreach ($prohibitedRoles as $role) {
        $actors[$role] = static fn (): User => cleaningDepositAdminActor($role);
    }

    $actors['permission only'] = static function (): User {
        $user = User::factory()->create(['is_active' => true]);
        foreach (['workers.update', 'pricing.view', 'settings.view'] as $permissionName) {
            $user->givePermissionTo(Permission::findOrCreate($permissionName, 'web'));
        }

        return $user;
    };

    foreach ([false, true] as $pilotEnabled) {
        config(['cleaning_transaction_admission.admin_deposit_pilot_enabled' => $pilotEnabled]);

        foreach ($actors as $actorFactory) {
            $actor = $actorFactory();
            Sanctum::actingAs($actor);
            $before = cleaningDepositAdminFinancialSnapshot();

            foreach (cleaningDepositAdminRoutes('missing-worker-id') as $route) {
                requestCleaningDepositAdminRoute($this, $route)->assertForbidden();
            }

            expect(cleaningDepositAdminFinancialSnapshot())->toBe($before);
        }
    }
});

it('rechecks persisted active state after the authenticated user was loaded', function (): void {
    $actor = cleaningDepositAdminActor();
    Sanctum::actingAs($actor);
    User::query()->whereKey($actor->id)->update(['is_active' => false]);

    $this->getJson('/api/v1/admin/cleaning/deposits/settings')->assertForbidden();
});

it('rechecks persisted roles after the authenticated user loaded an admin role', function (): void {
    $actor = cleaningDepositAdminActor();
    $actor->load('roles');
    Sanctum::actingAs($actor);
    $deletedRoleAssignments = DB::table('model_has_roles')
        ->where('model_type', $actor->getMorphClass())
        ->where('model_id', $actor->id)
        ->delete();
    expect($deletedRoleAssignments)->toBe(1);

    $this->getJson('/api/v1/admin/cleaning/deposits/settings')->assertForbidden();
});

it('keeps known-worker financial and eligibility state unchanged for denied finance actions', function (): void {
    $worker = cleaningDepositAdminWorker();
    CleaningDepositSetting::query()->create([
        'minimum_deposit_amount' => 12,
        'restriction_threshold_percent' => 100,
        'allowance_warning_threshold_percent' => 10,
        'trust_reject_after_accept_penalty' => 5,
        'trust_minimum_for_dispatch' => 50,
    ]);
    cleaningDepositAdminAccount($worker, balance: 40);
    CleaningDepositTransaction::query()->create([
        'worker_id' => $worker->id,
        'type' => 'deposit',
        'amount' => 40,
        'balance_before' => 0,
        'balance_after' => 40,
        'debt_settled_amount' => 0,
        'admin_revenue_withdrawn_amount' => 0,
        'debt_balance_before' => 0,
        'debt_balance_after' => 0,
        'reference' => 'existing-secret-reference',
        'notes' => 'existing private note that must stay private',
    ]);
    $actor = User::factory()->create(['is_active' => true]);
    Sanctum::actingAs($actor);

    foreach ([false, true] as $pilotEnabled) {
        config(['cleaning_transaction_admission.admin_deposit_pilot_enabled' => $pilotEnabled]);
        $before = cleaningDepositAdminFinancialSnapshot($worker);
        foreach (cleaningDepositAdminRoutes((string) $worker->id) as $route) {
            requestCleaningDepositAdminRoute($this, $route)->assertForbidden();
        }
        $this->postJson("/api/v1/admin/cleaning/deposits/{$worker->id}/deposit", ['amount' => -1])
            ->assertForbidden();

        expect(cleaningDepositAdminFinancialSnapshot($worker))->toBe($before);
    }
});

it('preserves the current admin deposit API payloads and statuses for active admin roles', function (string $role): void {
    config(['cleaning_transaction_admission.admin_deposit_pilot_enabled' => false]);
    Sanctum::actingAs(cleaningDepositAdminActor($role));

    $this->getJson('/api/v1/admin/cleaning/deposits/settings')
        ->assertOk()
        ->assertJsonStructure([
            'minimumDepositAmount',
            'allowedDebtLimit',
            'defaultMaxNegativeBalance',
            'restrictionThresholdPercent',
            'allowanceWarningThresholdPercent',
            'trustRejectAfterAcceptPenalty',
            'trustMinimumForDispatch',
            'isEnabled',
        ]);
    $this->putJson('/api/v1/admin/cleaning/deposits/settings', [
        'minimum_deposit_amount' => 15,
        'allowance_warning_threshold_percent' => 12,
    ])->assertOk()->assertJsonPath('allowanceWarningThresholdPercent', 12);

    $depositWorker = cleaningDepositAdminWorker();
    $this->postJson("/api/v1/admin/cleaning/deposits/{$depositWorker->id}/deposit", [
        'amount' => 25,
        'reference' => "t02_auth_{$role}_deposit",
    ])->assertCreated()->assertJsonPath('transaction.reference', "t02_auth_{$role}_deposit");

    $debtWorker = cleaningDepositAdminWorker();
    $this->postJson("/api/v1/admin/cleaning/deposits/{$debtWorker->id}/debt", ['amount' => 25])
        ->assertBadRequest();

    $allowanceWorker = cleaningDepositAdminWorker();
    $this->putJson("/api/v1/admin/cleaning/deposits/{$allowanceWorker->id}/allowance-limit", ['amount' => 25])
        ->assertOk()
        ->assertJsonPath('account.configuredAllowedDebtLimit', 25);

    $settlementWorker = cleaningDepositAdminWorker();
    cleaningDepositAdminAccount($settlementWorker, balance: 0, debt: 30);
    $this->postJson("/api/v1/admin/cleaning/deposits/{$settlementWorker->id}/settle-full-debt", [])
        ->assertCreated();

    $refundWorker = cleaningDepositAdminWorker();
    cleaningDepositAdminAccount($refundWorker, balance: 30);
    $this->postJson("/api/v1/admin/cleaning/deposits/{$refundWorker->id}/refund", [])
        ->assertCreated();

    $withdrawWorker = cleaningDepositAdminWorker();
    cleaningDepositAdminAccount($withdrawWorker, balance: 30);
    $this->postJson("/api/v1/admin/cleaning/deposits/{$withdrawWorker->id}/withdraw", [])
        ->assertCreated();

    $this->getJson("/api/v1/admin/cleaning/deposits/{$depositWorker->id}/transactions")
        ->assertOk()
        ->assertJsonStructure(['data', 'meta' => ['currentPage', 'lastPage', 'perPage', 'total', 'filters']]);

    $this->postJson('/api/v1/admin/cleaning/deposits/missing-worker-id/deposit', ['amount' => 25])
        ->assertNotFound();
})->with(['admin', 'Super Admin']);

it('leaves worker account, status, deposit, and transaction routes available to the worker', function (): void {
    $user = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 90,
        'is_active' => true,
        'is_suspended' => false,
        'security_deposit_status' => 'active',
    ]);
    cleaningDepositAdminAccount($worker, balance: 20);
    Sanctum::actingAs($user);

    $this->getJson('/api/v1/cleaning/worker/account/status')->assertOk();
    $this->getJson('/api/v1/cleaning/worker/account/deposit')->assertOk();
    $this->getJson('/api/v1/cleaning/worker/account/deposit/transactions')->assertOk();
    $this->getJson('/api/v1/cleaning/worker/account/transactions')->assertOk();
});
