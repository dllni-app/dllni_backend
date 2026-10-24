<?php

declare(strict_types=1);

use App\Models\BookingStatusLog;
use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\CleaningBookingService;
use Modules\Cleaning\Services\CleaningBookingWorkerCompletionService;

function workerCompletionFeeFixture(bool $hasAssignment = true): array
{
    CleaningDepositSetting::query()->firstOrCreate([], [
        'minimum_deposit_amount' => 0,
        'default_max_negative_balance' => 50000,
        'restriction_threshold_percent' => 100,
        'allowance_warning_threshold_percent' => 10,
        'trust_reject_after_accept_penalty' => 10,
        'trust_minimum_for_dispatch' => 0,
        'is_enabled' => true,
    ]);
    $worker = Worker::factory()->create([
        'trust_score' => 100,
        'is_active' => true,
        'is_suspended' => false,
        'security_deposit_status' => 'active',
    ]);
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 50,
        'debt_balance' => 0,
        'deposited_total' => 50,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 50000,
        'is_active' => true,
    ]);
    CleaningDepositTransaction::query()->create([
        'worker_id' => $worker->id,
        'type' => 'deposit',
        'amount' => 50,
        'reference' => 'qa-completion-start-'.$worker->id,
        'balance_before' => 0,
        'balance_after' => 50,
        'debt_balance_before' => 0,
        'debt_balance_after' => 0,
    ]);
    $booking = CleaningBooking::withoutEvents(fn (): CleaningBooking => CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'number_of_workers' => 1,
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion->value,
        'admin_margin_amount' => 100,
    ]));
    $assignment = $hasAssignment
        ? CleaningBookingWorkerAssignment::query()->create([
            'cleaning_booking_id' => $booking->id,
            'worker_id' => $worker->id,
            'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
            'accepted_at' => now(),
            'room_count' => 1,
            'rooms_weight' => 1,
            'service_share_amount' => 900,
            'travel_fee' => 0,
            'admin_margin_amount' => 100,
            'worker_amount' => 900,
            'currency' => 'SYP',
            'work_finished_at' => now(),
        ])
        : null;

    return [$worker, $booking, $assignment];
}

function completedBookingFeeCount(Worker $worker, CleaningBooking $booking): int
{
    $reference = CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX
        .hash('sha256', $worker->id.':'.$booking->id);

    return CleaningDepositTransaction::query()
        ->where('worker_id', $worker->id)->where('reference', $reference)->count();
}

it('commits one commission and completed assignment in the same legacy booking transition', function (): void {
    [$worker, $booking, $assignment] = workerCompletionFeeFixture();

    $completed = app(CleaningBookingWorkerCompletionService::class)
        ->confirm($booking, $worker->id, $assignment->id);

    expect($completed->status)->toBe(CleaningBookingStatus::Completed)
        ->and($assignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::Completed)
        ->and(completedBookingFeeCount($worker, $booking))->toBe(1)
        ->and((float) $worker->fresh('deposit')->deposit->current_balance)->toBe(0.0)
        ->and((float) $worker->fresh('deposit')->deposit->debt_balance)->toBe(50.0);
});

it('preserves the historical worker fallback when an awaiting booking has no assignment rows', function (): void {
    [$worker, $booking] = workerCompletionFeeFixture(false);

    $completed = app(CleaningBookingWorkerCompletionService::class)->confirm($booking);

    expect($completed->status)->toBe(CleaningBookingStatus::Completed)
        ->and(completedBookingFeeCount($worker, $booking))->toBe(1)
        ->and((float) $worker->fresh('deposit')->deposit->debt_balance)->toBe(50.0);
});

it('rejects an awaiting booking with positive commission and no worker recipient', function (): void {
    [, $booking] = workerCompletionFeeFixture(false);
    $booking->forceFill(['worker_id' => null])->saveQuietly();

    expect(fn () => app(CleaningBookingWorkerCompletionService::class)->confirm($booking))
        ->toThrow(ValidationException::class);

    expect($booking->fresh()->status)->toBe(CleaningBookingStatus::AwaitingCustomerCompletion)
        ->and(CleaningDepositTransaction::query()->where('type', 'commission')->count())->toBe(0);
});

it('does not charge a booking-wide commission for a booking with session-owned fees', function (): void {
    [$worker, $booking] = workerCompletionFeeFixture(false);
    CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => 1,
        'session_type' => 'event_day',
        'calculation_mode' => 'hours',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'required_workers' => 1,
        'coverage_status' => 'searching',
        'status' => CleaningBookingSessionStatus::Scheduled->value,
        'base_price' => 900,
        'addons_total' => 0,
        'materials_total' => 0,
        'special_services_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 100,
        'extension_fee_total' => 0,
        'cancellation_fee' => 0,
        'total_price' => 1000,
        'is_pricing_final' => true,
    ]);

    $completed = app(CleaningBookingWorkerCompletionService::class)->confirm($booking);

    expect($completed->status)->toBe(CleaningBookingStatus::Completed)
        ->and(completedBookingFeeCount($worker, $booking))->toBe(0)
        ->and((float) $worker->fresh('deposit')->deposit->current_balance)->toBe(50.0)
        ->and((float) $worker->fresh('deposit')->deposit->debt_balance)->toBe(0.0);
});

it('rolls back booking, assignment and fee together when the mandatory commission insert fails', function (): void {
    [$worker, $booking, $assignment] = workerCompletionFeeFixture();
    $injected = 0;
    DB::listen(static function (QueryExecuted $event) use (&$injected): void {
        if (str_contains(mb_strtolower($event->sql), 'insert into')
            && str_contains(mb_strtolower($event->sql), 'cleaning_deposit_transactions')
            && in_array('commission', $event->bindings, true)) {
            $injected++;
            throw new RuntimeException('QA_FORCED_COMPLETION_FEE_FAILURE');
        }
    });

    expect(fn () => app(CleaningBookingWorkerCompletionService::class)
        ->confirm($booking, $worker->id, $assignment->id))
        ->toThrow(RuntimeException::class, 'QA_FORCED_COMPLETION_FEE_FAILURE');

    expect($injected)->toBeGreaterThanOrEqual(2)
        ->and($booking->fresh()->status)->toBe(CleaningBookingStatus::AwaitingCustomerCompletion)
        ->and($assignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion)
        ->and(completedBookingFeeCount($worker, $booking))->toBe(0)
        ->and((float) $worker->fresh('deposit')->deposit->current_balance)->toBe(50.0)
        ->and((float) $worker->fresh('deposit')->deposit->debt_balance)->toBe(0.0);
});

it('rolls back an earlier worker commission when the second worker commission fails', function (): void {
    [$firstWorker, $booking, $firstAssignment] = workerCompletionFeeFixture();
    $booking->forceFill(['number_of_workers' => 2])->saveQuietly();
    $firstAssignment->forceFill([
        'status' => CleaningBookingWorkerAssignmentStatus::Completed,
        'admin_margin_amount' => 70,
    ])->save();

    $secondWorker = Worker::factory()->create([
        'trust_score' => 100,
        'is_active' => true,
        'is_suspended' => false,
        'security_deposit_status' => 'active',
    ]);
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $secondWorker->id,
        'current_balance' => 50,
        'debt_balance' => 0,
        'deposited_total' => 50,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 50000,
        'is_active' => true,
    ]);
    CleaningDepositTransaction::query()->create([
        'worker_id' => $secondWorker->id,
        'type' => 'deposit',
        'amount' => 50,
        'reference' => 'qa-completion-start-'.$secondWorker->id,
        'balance_before' => 0,
        'balance_after' => 50,
        'debt_balance_before' => 0,
        'debt_balance_after' => 0,
    ]);
    $pending = CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $secondWorker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
        'accepted_at' => now(),
        'room_count' => 1,
        'rooms_weight' => 1,
        'service_share_amount' => 920,
        'travel_fee' => 0,
        'admin_margin_amount' => 80,
        'worker_amount' => 920,
        'currency' => 'SYP',
        'work_finished_at' => now(),
    ]);

    $earlierInsert = 0;
    $failedSecondInsert = 0;
    DB::listen(static function (QueryExecuted $event) use ($secondWorker, &$earlierInsert, &$failedSecondInsert): void {
        $sql = mb_strtolower($event->sql);
        if (! str_contains($sql, 'insert into')
            || ! str_contains($sql, 'cleaning_deposit_transactions')
            || ! in_array('commission', $event->bindings, true)) {
            return;
        }
        if (in_array($secondWorker->id, $event->bindings, true)) {
            $failedSecondInsert++;
            throw new RuntimeException('QA_SECOND_WORKER_FEE_FAILURE');
        }
        $earlierInsert++;
    });

    expect(fn () => app(CleaningBookingWorkerCompletionService::class)
        ->confirm($booking, $secondWorker->id, $pending->id))
        ->toThrow(RuntimeException::class, 'QA_SECOND_WORKER_FEE_FAILURE');

    expect($earlierInsert)->toBeGreaterThanOrEqual(1)
        ->and($failedSecondInsert)->toBeGreaterThanOrEqual(1)
        ->and($booking->fresh()->status)->toBe(CleaningBookingStatus::AwaitingCustomerCompletion)
        ->and($firstAssignment->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::Completed)
        ->and($pending->fresh()->status)->toBe(CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion)
        ->and(completedBookingFeeCount($firstWorker, $booking))->toBe(0)
        ->and(completedBookingFeeCount($secondWorker, $booking))->toBe(0)
        ->and(BookingStatusLog::query()
            ->where('booking_id', $booking->id)
            ->where('booking_type', CleaningBooking::class)
            ->where('to_status', CleaningBookingStatus::Completed->value)->count())->toBe(0)
        ->and((float) $firstWorker->fresh('deposit')->deposit->current_balance)->toBe(50.0)
        ->and((float) $firstWorker->fresh('deposit')->deposit->debt_balance)->toBe(0.0)
        ->and((float) $secondWorker->fresh('deposit')->deposit->current_balance)->toBe(50.0)
        ->and((float) $secondWorker->fresh('deposit')->deposit->debt_balance)->toBe(0.0);
});

it('rolls back direct worker success when its legacy fee cannot be inserted', function (): void {
    [$worker, $booking] = workerCompletionFeeFixture(false);
    $booking->forceFill(['status' => CleaningBookingStatus::InProgress])->saveQuietly();
    Auth::login($worker->user);
    $failedCommissionInserts = 0;
    DB::listen(static function (QueryExecuted $event) use (&$failedCommissionInserts): void {
        $sql = mb_strtolower($event->sql);
        if (str_contains($sql, 'insert into')
            && str_contains($sql, 'cleaning_deposit_transactions')
            && in_array('commission', $event->bindings, true)) {
            $failedCommissionInserts++;
            throw new RuntimeException('QA_WORKER_FINISH_MANDATORY_FEE_FAILURE');
        }
    });

    expect(fn () => app(CleaningBookingService::class)->finish($booking, 'success'))
        ->toThrow(RuntimeException::class, 'QA_WORKER_FINISH_MANDATORY_FEE_FAILURE');

    expect($failedCommissionInserts)->toBeGreaterThanOrEqual(2)
        ->and($booking->fresh()->status)->toBe(CleaningBookingStatus::InProgress)
        ->and(completedBookingFeeCount($worker, $booking))->toBe(0)
        ->and((float) $worker->fresh('deposit')->deposit->current_balance)->toBe(50.0)
        ->and((float) $worker->fresh('deposit')->deposit->debt_balance)->toBe(0.0);
});
