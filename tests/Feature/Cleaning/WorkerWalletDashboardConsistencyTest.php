<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\AdminCleaningTransactionService;

beforeEach(function (): void {
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 0,
            'default_max_negative_balance' => 50000,
            'restriction_threshold_percent' => 100,
            'is_enabled' => true,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 0,
        ],
    );
});

it('returns the same worker financial values used by the Filament dashboard', function (): void {
    $user = User::factory()->create(['module_type' => UserModuleType::CleaningWorker]);
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 100,
        'security_deposit_status' => 'active',
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 750,
        'debt_balance' => 0,
        'deposited_total' => 1000,
        'withdrawn_total' => 250,
        'admin_revenue_withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100,
        'is_active' => true,
    ]);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::Completed->value,
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Completed->value,
        'accepted_at' => now()->subHours(2),
        'work_finished_at' => now(),
        'service_share_amount' => 2000,
        'travel_fee' => 250,
        'admin_margin_amount' => 500,
        'worker_amount' => 1750,
        'currency' => 'SYP',
    ]);

    CleaningDepositTransaction::query()->create([
        'worker_id' => $worker->id,
        'type' => 'commission',
        'amount' => 500,
        'balance_before' => 1250,
        'balance_after' => 750,
        'debt_balance_before' => 0,
        'debt_balance_after' => 0,
        'reference' => CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'wallet-consistency',
    ]);

    $expected = app(AdminCleaningTransactionService::class)->snapshot($worker->fresh(['deposit']));

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/cleaning/worker/account/deposit')
        ->assertOk()
        ->assertJsonPath('workerId', $worker->id)
        ->assertJsonPath('currentBalance', $expected['depositBalance'])
        ->assertJsonPath('allowedDebtLimit', $expected['allowedDebtLimit'])
        ->assertJsonPath('totalRevenue', $expected['totalRevenue'])
        ->assertJsonPath('completedJobs', $expected['completedJobs'])
        ->assertJsonPath('totalCommission', $expected['totalCommission'])
        ->assertJsonPath('adminCommissionBalance', $expected['adminCommissionBalance'])
        ->assertJsonPath('grossInvoicesAmount', round(
            (float) $expected['totalRevenue'] + (float) $expected['adminCommissionBalance'],
            2,
        ));
});

it('marks a legacy fully withdrawn insurance account as inactive', function (): void {
    $user = User::factory()->create(['module_type' => UserModuleType::CleaningWorker]);
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 100,
        'security_deposit_status' => 'active',
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 0,
        'deposited_total' => 2000000,
        'withdrawn_total' => 2000000,
        'admin_revenue_withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100000,
        'is_active' => true,
    ]);

    CleaningDepositTransaction::query()->create([
        'worker_id' => $worker->id,
        'type' => 'withdrawal',
        'amount' => 2000000,
        'balance_before' => 2000000,
        'balance_after' => 0,
        'debt_balance_before' => 0,
        'debt_balance_after' => 0,
        'reference' => 'legacy-full-withdrawal',
    ]);

    Sanctum::actingAs($user);

    $this->getJson('/api/v1/cleaning/worker/account/deposit')
        ->assertOk()
        ->assertJsonPath('currentBalance', 0)
        ->assertJsonPath('status', 'inactive')
        ->assertJsonPath('isFinancialAccountActive', false)
        ->assertJsonPath('isActive', false)
        ->assertJsonPath('isEligibleForNewRequests', false);
});
