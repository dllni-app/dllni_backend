<?php

declare(strict_types=1);

use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Enums\UserModuleType;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use App\Services\DisputeFinancialPenaltyService;
use Illuminate\Support\Facades\Queue;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

beforeEach(function (): void {
    Queue::fake();
});

it('deducts the dispute penalty from deposit first and records the uncovered amount as debt', function (): void {
    $admin = User::factory()->create();
    $customer = User::factory()->create();
    $workerUser = User::factory()->create([
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 300,
        'debt_balance' => 0,
        'deposited_total' => 300,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 1000,
        'is_active' => true,
    ]);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::UnderDispute,
    ]);
    $dispute = $booking->disputes()->create([
        'ticket_number' => 'DSP-FIN-0001',
        'description' => 'Financial dispute test',
        'category' => DisputeCategory::PoorQuality->value,
        'status' => DisputeStatus::Open->value,
        'worker_earnings_frozen' => true,
    ]);

    $updated = app(DisputeFinancialPenaltyService::class)->apply(
        dispute: $dispute,
        worker: $worker,
        amount: 500,
        notes: 'Confirmed admin penalty',
        appliedByAdminId: $admin->id,
        keepWorkerEarningsFrozen: false,
    );

    $account = CleaningWorkerDeposit::query()->where('worker_id', $worker->id)->firstOrFail();
    $transaction = CleaningDepositTransaction::query()->findOrFail($updated->financial_penalty_transaction_id);

    expect((float) $account->current_balance)->toBe(0.0)
        ->and((float) $account->debt_balance)->toBe(200.0)
        ->and((float) $transaction->amount)->toBe(500.0)
        ->and($transaction->type)->toBe('debt')
        ->and($transaction->reference)->toBe('dispute_worker_penalty:'.$dispute->id)
        ->and((int) $updated->financial_penalty_worker_id)->toBe($worker->id)
        ->and((float) $updated->financial_penalty_amount)->toBe(500.0)
        ->and((int) $updated->financial_penalty_applied_by)->toBe($admin->id)
        ->and($updated->worker_earnings_frozen)->toBeFalse();
});

it('prevents applying the financial penalty twice to the same dispute', function (): void {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create([
        'module_type' => UserModuleType::CleaningWorker->value,
    ]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
    ]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::UnderDispute,
    ]);
    $dispute = $booking->disputes()->create([
        'ticket_number' => 'DSP-FIN-0002',
        'description' => 'Duplicate financial dispute test',
        'category' => DisputeCategory::Other->value,
        'status' => DisputeStatus::Open->value,
        'worker_earnings_frozen' => true,
    ]);

    $service = app(DisputeFinancialPenaltyService::class);
    $service->apply($dispute, $worker, 100, 'First penalty', null);

    expect(fn () => $service->apply($dispute->fresh(), $worker, 100, 'Duplicate penalty', null))
        ->toThrow(\InvalidArgumentException::class);

    expect(CleaningDepositTransaction::query()
        ->where('reference', 'dispute_worker_penalty:'.$dispute->id)
        ->count())->toBe(1);
});
