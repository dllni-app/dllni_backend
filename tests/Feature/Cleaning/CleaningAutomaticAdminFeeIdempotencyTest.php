<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\DepositService;

beforeEach(function (): void {
    CleaningDepositSetting::query()->create([
        'minimum_deposit_amount' => 0,
        'default_max_negative_balance' => 50000,
        'restriction_threshold_percent' => 100,
        'allowance_warning_threshold_percent' => 10,
        'trust_reject_after_accept_penalty' => 10,
        'trust_minimum_for_dispatch' => 0,
        'is_enabled' => true,
    ]);
});

function makeAdminFeeIdempotencyFixture(float $deposit = 50): array
{
    $worker = Worker::factory()->create([
        'trust_score' => 100,
        'security_deposit_status' => 'active',
        'is_active' => true,
        'is_suspended' => false,
    ]);
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => $deposit,
        'debt_balance' => 0,
        'deposited_total' => $deposit,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 50000,
        'is_active' => true,
    ]);
    $booking = CleaningBooking::withoutEvents(fn (): CleaningBooking => CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion->value,
        'admin_margin_amount' => 100,
    ]));

    return [$worker, $booking];
}

function makeAdminFeeIdempotencySession(CleaningBooking $booking, int $sequence): CleaningBookingSession
{
    return CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => $sequence,
        'session_type' => 'event_day',
        'calculation_mode' => 'hours',
        'scheduled_date' => now()->addDays($sequence)->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'required_workers' => 1,
        'coverage_status' => 'searching',
        'status' => CleaningBookingSessionStatus::Scheduled->value,
        'base_price' => 300,
        'addons_total' => 0,
        'materials_total' => 0,
        'special_services_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 40,
        'extension_fee_total' => 0,
        'cancellation_fee' => 0,
        'total_price' => 340,
        'is_pricing_final' => true,
    ]);
}

it('charges one legacy automatic commission per worker and booking despite repeat calls', function (): void {
    [$worker, $booking] = makeAdminFeeIdempotencyFixture();
    $deposits = app(DepositService::class);

    $first = $deposits->recordAdminFeeDebit($worker, $booking, 100);
    $duplicate = $deposits->recordAdminFeeDebit($worker, $booking, 100);
    $zero = $deposits->recordAdminFeeDebit($worker, $booking, 0);

    $account = $worker->fresh('deposit')->deposit;
    expect($first)->toBeInstanceOf(CleaningDepositTransaction::class)
        ->and($duplicate)->toBeNull()
        ->and($zero)->toBeNull()
        ->and((float) $account->current_balance)->toBe(0.0)
        ->and((float) $account->debt_balance)->toBe(50.0)
        ->and(CleaningDepositTransaction::query()->where('worker_id', $worker->id)->where('type', 'commission')->count())->toBe(1);
});

it('creates a previously missing worker account only for the first positive commission', function (): void {
    $worker = Worker::factory()->create([
        'trust_score' => 100,
        'is_active' => true,
        'is_suspended' => false,
        'security_deposit_status' => 'active',
    ]);
    $booking = CleaningBooking::withoutEvents(fn (): CleaningBooking => CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion->value,
        'admin_margin_amount' => 100,
    ]));
    $deposits = app(DepositService::class);

    expect($worker->deposit()->exists())->toBeFalse()
        ->and($deposits->recordAdminFeeDebit($worker, $booking, 0))->toBeNull()
        ->and($worker->deposit()->exists())->toBeFalse();

    $first = $deposits->recordAdminFeeDebit($worker, $booking, 100);
    $second = $deposits->recordAdminFeeDebit($worker, $booking, 100);
    $account = $worker->fresh('deposit')->deposit;

    expect($first)->toBeInstanceOf(CleaningDepositTransaction::class)
        ->and($second)->toBeNull()
        ->and($worker->deposit()->count())->toBe(1)
        ->and((float) $account->current_balance)->toBe(0.0)
        ->and((float) $account->debt_balance)->toBe(100.0)
        ->and(CleaningDepositTransaction::query()->where('worker_id', $worker->id)->where('type', 'commission')->count())->toBe(1);
});

it('deduplicates one session identity without rejecting a distinct session on the same booking', function (): void {
    [$worker, $booking] = makeAdminFeeIdempotencyFixture(100);
    $firstSession = makeAdminFeeIdempotencySession($booking, 1);
    $secondSession = makeAdminFeeIdempotencySession($booking, 2);
    $deposits = app(DepositService::class);

    $first = $deposits->recordSessionAdminFeeDebit($worker, $booking, $firstSession, 40);
    $repeat = $deposits->recordSessionAdminFeeDebit($worker, $booking, $firstSession, 40);
    $second = $deposits->recordSessionAdminFeeDebit($worker, $booking, $secondSession, 40);

    $account = $worker->fresh('deposit')->deposit;
    $ledger = CleaningDepositTransaction::query()->where('worker_id', $worker->id)
        ->where('type', 'commission')->orderBy('id')->get();
    expect($first)->toBeInstanceOf(CleaningDepositTransaction::class)
        ->and($repeat)->toBeNull()
        ->and($second)->toBeInstanceOf(CleaningDepositTransaction::class)
        ->and($ledger)->toHaveCount(2)
        ->and($ledger[0]->reference)->not->toBe($ledger[1]->reference)
        ->and((float) $account->current_balance)->toBe(20.0)
        ->and((float) $account->debt_balance)->toBe(0.0);
});

it('rolls back both the automatic commission and balance change on an enclosing failure', function (): void {
    [$worker, $booking] = makeAdminFeeIdempotencyFixture(100);
    $deposits = app(DepositService::class);

    try {
        DB::transaction(function () use ($deposits, $worker, $booking): void {
            $deposits->recordAdminFeeDebit($worker, $booking, 40);
            throw new RuntimeException('Expected test rollback.');
        });
        throw new RuntimeException('The expected rollback did not run.');
    } catch (RuntimeException $exception) {
        expect($exception->getMessage())->toBe('Expected test rollback.');
    }

    expect(CleaningDepositTransaction::query()->where('worker_id', $worker->id)->where('type', 'commission')->count())->toBe(0)
        ->and((float) $worker->fresh('deposit')->deposit->current_balance)->toBe(100.0);

    $retried = $deposits->recordAdminFeeDebit($worker->fresh(), $booking->fresh(), 40);
    expect($retried)->toBeInstanceOf(CleaningDepositTransaction::class)
        ->and(CleaningDepositTransaction::query()->where('worker_id', $worker->id)->where('type', 'commission')->count())->toBe(1)
        ->and((float) $worker->fresh('deposit')->deposit->current_balance)->toBe(60.0);
});
