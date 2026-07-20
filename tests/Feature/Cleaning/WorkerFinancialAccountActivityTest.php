<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use Modules\Cleaning\Services\DepositService;

beforeEach(function (): void {
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 0,
            'default_max_negative_balance' => 100,
            'restriction_threshold_percent' => 100,
            'is_enabled' => true,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 0,
        ],
    );
});

it('keeps the financial account active through the indebtedness limit', function (): void {
    $worker = Worker::factory()->create(['trust_score' => 100]);

    $account = CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 0,
        'debt_balance' => 100,
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100,
        'is_active' => false,
    ]);

    expect((bool) $account->fresh()->is_active)->toBeTrue();
});

it('deactivates the financial account after indebtedness exceeds the limit', function (): void {
    $worker = Worker::factory()->create(['trust_score' => 100, 'security_deposit_status' => 'active']);

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

    $booking = Modules\Cleaning\Models\CleaningBooking::factory()->create(['worker_id' => $worker->id]);
    app(DepositService::class)->recordAdminFeeDebit($worker, $booking, 150);

    $account = $worker->fresh('deposit')->deposit;
    expect((float) $account->debt_balance)->toBe(150.0)
        ->and((bool) $account->is_active)->toBeFalse()
        ->and(app(DepositService::class)->isWorkerEligibleForNewRequests($worker->fresh(['deposit'])))->toBeFalse();
});
