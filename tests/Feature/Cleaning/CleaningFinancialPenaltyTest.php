<?php

declare(strict_types=1);

use App\Models\CleaningDepositTransaction;
use App\Models\CleaningFinancialPenalty;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\CleaningFinancialPenaltyNotification;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use InvalidArgumentException;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Services\AdminCleaningTransactionService;
use Modules\Cleaning\Services\CleaningCancellationFinancialPenaltyService;
use Modules\Cleaning\Services\DepositService;

function createPenaltyWorker(float $depositBalance = 0, float $debtBalance = 0): Worker
{
    $user = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 100,
        'is_active' => true,
        'is_suspended' => false,
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => $depositBalance,
        'debt_balance' => $debtBalance,
        'deposited_total' => max(0, $depositBalance),
        'withdrawn_total' => 0,
        'admin_revenue_withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100000,
        'is_active' => true,
    ]);

    return $worker->fresh(['user', 'deposit']);
}

function createWorkerCancelledBooking(Worker $worker, float $totalPrice = 1000): CleaningBooking
{
    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::Cancelled,
        'total_price' => $totalPrice,
        'cancelled_at' => now(),
        'cancellation_reason' => 'Worker cancelled the booking.',
        'cancelled_by_role' => 'worker',
        'cancelled_by_user_id' => $worker->user_id,
        'cancelled_by_worker_id' => $worker->id,
        'cancellation_offset_minutes' => 60,
    ]);

    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Cancelled,
        'status_before_booking_cancellation' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'booking_cancelled_at' => now(),
        'cancelled_by_this_worker' => true,
        'accepted_at' => now()->subHour(),
        'room_count' => 0,
        'rooms_weight' => 0,
        'service_share_amount' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 0,
        'currency' => 'SYP',
    ]);

    return $booking->fresh(['workerAssignments', 'financialPenalty']);
}

it('stores the worker cancellation actor timing and assignment snapshots', function (): void {
    Carbon::setTestNow(Carbon::parse('2026-07-24 10:00:00'));

    try {
        $worker = createPenaltyWorker(500);
        $this->actingAs($worker->user);

        $booking = CleaningBooking::factory()->create([
            'worker_id' => $worker->id,
            'status' => CleaningBookingStatus::WorkerAssigned,
            'scheduled_date' => '2026-07-24',
            'scheduled_time' => '12:00',
        ]);

        $assignment = CleaningBookingWorkerAssignment::query()->create([
            'cleaning_booking_id' => $booking->id,
            'worker_id' => $worker->id,
            'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
            'accepted_at' => now()->subHour(),
            'room_count' => 0,
            'rooms_weight' => 0,
            'service_share_amount' => 0,
            'travel_fee' => 0,
            'admin_margin_amount' => 0,
            'worker_amount' => 0,
            'currency' => 'SYP',
        ]);

        $booking->update([
            'status' => CleaningBookingStatus::Cancelled,
            'cancelled_at' => now(),
            'cancellation_reason' => 'Emergency',
        ]);

        $booking->refresh();
        $assignment->refresh();

        expect($booking->cancelled_by_role)->toBe('worker')
            ->and($booking->cancelled_by_user_id)->toBe($worker->user_id)
            ->and($booking->cancelled_by_worker_id)->toBe($worker->id)
            ->and($booking->cancellation_offset_minutes)->toBe(120)
            ->and($assignment->status)->toBe(CleaningBookingWorkerAssignmentStatus::Cancelled)
            ->and($assignment->status_before_booking_cancellation)->toBe(CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value)
            ->and($assignment->cancelled_by_this_worker)->toBeTrue()
            ->and($assignment->booking_cancelled_at)->not->toBeNull();
    } finally {
        Carbon::setTestNow();
    }
});

it('creates one deposit-backed penalty and notifies the cancelling worker', function (): void {
    Notification::fake();
    $worker = createPenaltyWorker(500);
    $booking = createWorkerCancelledBooking($worker, 1000);

    $penalty = app(CleaningCancellationFinancialPenaltyService::class)->apply(
        booking: $booking,
        amount: 300,
        notes: 'Late cancellation penalty.',
        appliedByAdminId: null,
    );

    $worker->refresh()->load('deposit');

    expect($penalty->financial_source)->toBe(CleaningFinancialPenalty::SOURCE_DEPOSIT)
        ->and($penalty->status)->toBe(CleaningFinancialPenalty::STATUS_ACTIVE)
        ->and((float) $penalty->amount)->toBe(300.0)
        ->and((float) $worker->deposit->current_balance)->toBe(200.0)
        ->and((float) $worker->deposit->debt_balance)->toBe(0.0)
        ->and($penalty->financialTransaction?->type)->toBe('debt')
        ->and($penalty->financialTransaction?->reference)->toBe('cleaning_cancellation_penalty:'.$booking->id);

    Notification::assertSentTo($worker->user, CleaningFinancialPenaltyNotification::class);
});

it('classifies a penalty as debt when the deposit does not fully cover it', function (): void {
    Notification::fake();
    $worker = createPenaltyWorker(100);
    $booking = createWorkerCancelledBooking($worker, 1000);

    $penalty = app(CleaningCancellationFinancialPenaltyService::class)->apply(
        booking: $booking,
        amount: 300,
        notes: 'Cancellation penalty.',
        appliedByAdminId: null,
    );

    $worker->refresh()->load('deposit');

    expect($penalty->financial_source)->toBe(CleaningFinancialPenalty::SOURCE_DEBT)
        ->and((float) $worker->deposit->current_balance)->toBe(0.0)
        ->and((float) $worker->deposit->debt_balance)->toBe(200.0);
});

it('rejects duplicate penalties and penalties larger than the booking total', function (): void {
    Notification::fake();
    $worker = createPenaltyWorker(1000);
    $booking = createWorkerCancelledBooking($worker, 500);
    $service = app(CleaningCancellationFinancialPenaltyService::class);

    expect(fn () => $service->apply($booking, 501, 'Too high.', null))
        ->toThrow(InvalidArgumentException::class, 'لا يمكن أن تتجاوز الغرامة إجمالي قيمة الطلب.');

    $service->apply($booking, 100, 'First penalty.', null);

    expect(fn () => $service->apply($booking->fresh(), 100, 'Duplicate penalty.', null))
        ->toThrow(InvalidArgumentException::class, 'تمت إضافة غرامة مالية لهذا الطلب مسبقاً.');

    expect(CleaningFinancialPenalty::query()->where('cleaning_booking_id', $booking->id)->count())->toBe(1)
        ->and(CleaningDepositTransaction::query()->where('reference', 'cleaning_cancellation_penalty:'.$booking->id)->count())->toBe(1);
});

it('clears a debt-backed penalty only when the worker debt reaches zero', function (): void {
    Notification::fake();
    $worker = createPenaltyWorker(0);
    $booking = createWorkerCancelledBooking($worker, 1000);
    $penalty = app(CleaningCancellationFinancialPenaltyService::class)->apply($booking, 300, 'Debt penalty.', null);

    app(DepositService::class)->recordDeposit($worker->fresh(), 100, 'partial-debt-payment');
    expect($penalty->fresh()->status)->toBe(CleaningFinancialPenalty::STATUS_ACTIVE);

    app(DepositService::class)->recordDeposit($worker->fresh(), 200, 'full-debt-payment');

    expect($penalty->fresh()->status)->toBe(CleaningFinancialPenalty::STATUS_CLEARED)
        ->and($penalty->fresh()->cleared_at)->not->toBeNull()
        ->and((float) $worker->fresh()->deposit->debt_balance)->toBe(0.0);
});

it('clears a deposit-backed penalty after the full financial account refund', function (): void {
    Notification::fake();
    $worker = createPenaltyWorker(500);
    $booking = createWorkerCancelledBooking($worker, 1000);
    $penalty = app(CleaningCancellationFinancialPenaltyService::class)->apply($booking, 300, 'Deposit penalty.', null);

    app(AdminCleaningTransactionService::class)->refundFullBalance(
        worker: $worker->fresh(['deposit']),
        notes: 'Close worker financial account.',
        createdByAdminId: null,
    );

    expect($penalty->fresh()->status)->toBe(CleaningFinancialPenalty::STATUS_CLEARED)
        ->and($penalty->fresh()->cleared_at)->not->toBeNull()
        ->and((float) $worker->fresh()->deposit->current_balance)->toBe(0.0);
});
