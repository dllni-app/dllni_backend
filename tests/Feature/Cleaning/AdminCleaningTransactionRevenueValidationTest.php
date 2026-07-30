<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\CleaningDepositSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
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

it('blocks an administration loan when the worker account has revenue', function (): void {
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
        'deposited_total' => 0,
        'withdrawn_total' => 0,
        'admin_revenue_withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 50000,
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

    $freshWorker = $worker->fresh(['deposit']);
    $service = app(AdminCleaningTransactionService::class);
    $snapshot = $service->snapshot($freshWorker);

    expect($snapshot['depositBalance'])->toBe(0.0)
        ->and($snapshot['debtBalance'])->toBe(0.0)
        ->and($snapshot['totalRevenue'])->toBe(1750.0)
        ->and($service->validationMessage($freshWorker, 'debt', 5000))->not->toBeNull();

    expect(fn () => $service->create(
        worker: $freshWorker,
        type: 'debt',
        amount: 5000,
        notes: 'Administration-funded deposit.',
        createdByAdminId: null,
    ))->toThrow(InvalidArgumentException::class);
});
