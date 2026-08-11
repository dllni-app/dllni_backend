<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Filament\Resources\CleaningWorkers\Pages\ListCleaningWorkers;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Livewire\Livewire;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $adminUser = User::factory()->create(['email' => 'cleaning-worker-filters-admin@example.com']);
    $adminUser->assignRole('admin');
    $this->actingAs($adminUser);
});

it('filters financially blocked workers independently from administrative suspension', function (): void {
    $blockedWorker = createFinancialFilterWorker();
    createFinancialFilterDeposit($blockedWorker, currentBalance: 100, debtBalance: 75, debtLimit: 50);

    $availableWorker = createFinancialFilterWorker();
    createFinancialFilterDeposit($availableWorker, currentBalance: 0, debtBalance: 25, debtLimit: 50);

    $suspendedButFinanciallyAvailableWorker = createFinancialFilterWorker(['is_suspended' => true]);
    createFinancialFilterDeposit($suspendedButFinanciallyAvailableWorker, currentBalance: 0, debtBalance: 0, debtLimit: 0);

    $workerWithoutDeposit = createFinancialFilterWorker();

    Livewire::test(ListCleaningWorkers::class)
        ->filterTable('financially_blocked', true)
        ->assertCanSeeTableRecords([$blockedWorker])
        ->assertCanNotSeeTableRecords([
            $availableWorker,
            $suspendedButFinanciallyAvailableWorker,
            $workerWithoutDeposit,
        ]);

    Livewire::test(ListCleaningWorkers::class)
        ->filterTable('financially_blocked', false)
        ->assertCanSeeTableRecords([
            $availableWorker,
            $suspendedButFinanciallyAvailableWorker,
            $workerWithoutDeposit,
        ])
        ->assertCanNotSeeTableRecords([$blockedWorker]);
});

it('filters workers by whether their current debt is greater than zero', function (): void {
    $workerWithDebt = createFinancialFilterWorker();
    createFinancialFilterDeposit($workerWithDebt, currentBalance: 100, debtBalance: 25, debtLimit: 50);

    $workerWithoutDebt = createFinancialFilterWorker();
    createFinancialFilterDeposit($workerWithoutDebt, currentBalance: 100, debtBalance: 0, debtLimit: 50);

    $workerWithoutDeposit = createFinancialFilterWorker();

    Livewire::test(ListCleaningWorkers::class)
        ->filterTable('has_debt', true)
        ->assertCanSeeTableRecords([$workerWithDebt])
        ->assertCanNotSeeTableRecords([$workerWithoutDebt, $workerWithoutDeposit]);

    Livewire::test(ListCleaningWorkers::class)
        ->filterTable('has_debt', false)
        ->assertCanSeeTableRecords([$workerWithoutDebt, $workerWithoutDeposit])
        ->assertCanNotSeeTableRecords([$workerWithDebt]);
});

it('filters workers by reserved commissions from active cleaning orders and combines with debt', function (): void {
    $workerWithReservedCommission = createFinancialFilterWorker();
    createFinancialFilterDeposit($workerWithReservedCommission, currentBalance: 100, debtBalance: 25, debtLimit: 50);

    $workerWithCompletedOrderCommission = createFinancialFilterWorker();
    createFinancialFilterDeposit($workerWithCompletedOrderCommission, currentBalance: 100, debtBalance: 25, debtLimit: 50);

    $workerWithZeroReservedCommission = createFinancialFilterWorker();
    createFinancialFilterDeposit($workerWithZeroReservedCommission, currentBalance: 100, debtBalance: 0, debtLimit: 50);

    $activeBooking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::InProgress->value,
    ]);
    $completedBooking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Completed->value,
    ]);
    $zeroCommissionBooking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::InProgress->value,
    ]);

    createFinancialFilterAssignment($activeBooking, $workerWithReservedCommission, 250);
    createFinancialFilterAssignment($completedBooking, $workerWithCompletedOrderCommission, 250);
    createFinancialFilterAssignment($zeroCommissionBooking, $workerWithZeroReservedCommission, 0);

    Livewire::test(ListCleaningWorkers::class)
        ->filterTable('has_reserved_active_commission', true)
        ->assertCanSeeTableRecords([$workerWithReservedCommission])
        ->assertCanNotSeeTableRecords([$workerWithCompletedOrderCommission, $workerWithZeroReservedCommission]);

    Livewire::test(ListCleaningWorkers::class)
        ->filterTable('has_reserved_active_commission', false)
        ->assertCanSeeTableRecords([$workerWithCompletedOrderCommission, $workerWithZeroReservedCommission])
        ->assertCanNotSeeTableRecords([$workerWithReservedCommission]);

    Livewire::test(ListCleaningWorkers::class)
        ->filterTable('has_debt', true)
        ->filterTable('has_reserved_active_commission', true)
        ->assertCanSeeTableRecords([$workerWithReservedCommission])
        ->assertCanNotSeeTableRecords([$workerWithCompletedOrderCommission, $workerWithZeroReservedCommission]);
});

/** @param array<string, mixed> $attributes */
function createFinancialFilterWorker(array $attributes = []): Worker
{
    $workerUser = User::factory()->create([
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);

    return Worker::factory()->create([
        'user_id' => $workerUser->id,
        ...$attributes,
    ]);
}

function createFinancialFilterDeposit(
    Worker $worker,
    float $currentBalance,
    float $debtBalance,
    float $debtLimit,
): CleaningWorkerDeposit {
    return CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => $currentBalance,
        'debt_balance' => $debtBalance,
        'deposited_total' => $currentBalance,
        'withdrawn_total' => 0,
        'admin_revenue_withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => $debtLimit,
        'is_active' => true,
    ]);
}

function createFinancialFilterAssignment(
    CleaningBooking $booking,
    Worker $worker,
    float $adminMarginAmount,
): CleaningBookingWorkerAssignment {
    return CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::InProgress->value,
        'service_share_amount' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => $adminMarginAmount,
        'worker_amount' => 0,
        'currency' => 'SYP',
    ]);
}
