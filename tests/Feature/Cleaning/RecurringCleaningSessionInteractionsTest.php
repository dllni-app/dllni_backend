<?php

declare(strict_types=1);

use App\Enums\DisputeCategory;
use App\Enums\WorkerCustomerRatingType;
use App\Models\CleaningDepositSetting;
use App\Models\CleaningDepositTransaction;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Services\CleaningBookingSchedulePresenter;
use Modules\Cleaning\Services\CleaningBookingSessionInteractionService;
use Modules\Cleaning\Services\CleaningBookingSessionLifecycleService;

use function Pest\Laravel\postJson;

/** @return array{0:User,1:Worker,2:CleaningBooking,3:CleaningBookingSession,4:CleaningBookingSession} */
function makeRecurringSessionInteractionContext(): array
{
    CleaningDepositSetting::query()->firstOrCreate([], [
        'minimum_deposit_amount' => 0,
        'default_max_negative_balance' => 100000,
        'restriction_threshold_percent' => 100,
        'allowance_warning_threshold_percent' => 10,
        'is_enabled' => true,
        'trust_reject_after_accept_penalty' => 10,
        'trust_minimum_for_dispatch' => 0,
    ]);

    $customer = User::factory()->create(['is_active' => true]);
    $workerUser = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'is_active' => true,
        'is_suspended' => false,
        'trust_score' => 100,
    ]);
    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 100000,
        'debt_balance' => 0,
        'deposited_total' => 100000,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 100000,
        'is_active' => true,
    ]);

    $start = now(config('app.timezone'))->subDay()->setTime(10, 0);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion->value,
        'property_type' => 'apartment',
        'scheduled_date' => $start->toDateString(),
        'scheduled_time' => $start->format('H:i'),
        'estimated_hours' => 4,
        'total_hours' => 4,
        'base_price' => 2000,
        'admin_margin_amount' => 200,
        'total_price' => 2200,
    ]);

    $sessions = [];
    foreach ([1, 2] as $sequence) {
        $session = CleaningBookingSession::query()->create([
            'cleaning_booking_id' => $booking->id,
            'sequence' => $sequence,
            'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
            'calculation_mode' => 'task',
            'scheduled_date' => $start->copy()->addDays($sequence - 1)->toDateString(),
            'scheduled_time' => $start->format('H:i'),
            'duration_hours' => 2,
            'required_workers' => 1,
            'coverage_status' => CleaningBookingSessionCoverageStatus::FullyCovered->value,
            'status' => CleaningBookingSessionStatus::AwaitingCustomerCompletion->value,
            'base_price' => 1000,
            'admin_margin_amount' => 100,
            'total_price' => 1100,
            'is_pricing_final' => true,
            'payment_status' => 'ready',
            'work_started_at' => $start->copy()->addHours(1),
            'work_finished_at' => $start->copy()->addHours(3),
        ]);
        CleaningBookingSessionWorkerAssignment::query()->create([
            'cleaning_booking_session_id' => $session->id,
            'worker_id' => $worker->id,
            'status' => CleaningBookingWorkerAssignmentStatus::AwaitingCustomerCompletion->value,
            'accepted_at' => $start->copy()->subHour(),
            'work_started_at' => $start->copy()->addHour(),
            'work_finished_at' => $start->copy()->addHours(3),
            'service_share_amount' => 900,
            'travel_fee' => 0,
            'admin_margin_amount' => 100,
            'worker_amount' => 900,
            'currency' => 'SYP',
        ]);
        $sessions[] = $session;
    }

    return [$customer, $worker, $booking, $sessions[0], $sessions[1]];
}

it('settles each recurring session commission exactly once without parent double charging', function (): void {
    [$customer, $worker, $booking, $first, $second] = makeRecurringSessionInteractionContext();
    $lifecycle = app(CleaningBookingSessionLifecycleService::class);

    $first = $lifecycle->confirmCompletion($booking->fresh(), $first->fresh(), (int) $customer->id);
    expect($first->payment_status)->toBe('settled')
        ->and($first->payment_settled_at)->not->toBeNull();

    $second = $lifecycle->confirmCompletion($booking->fresh(), $second->fresh(), (int) $customer->id);
    expect($second->payment_status)->toBe('settled')
        ->and($booking->fresh()->status)->toBe(CleaningBookingStatus::Completed);

    $transactions = CleaningDepositTransaction::query()
        ->where('worker_id', $worker->id)
        ->where('reference', 'like', CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'%')
        ->get();

    expect($transactions)->toHaveCount(2)
        ->and($transactions->pluck('amount')->map(fn ($amount): float => (float) $amount)->all())
        ->each->toBe(100.0);

    // Idempotent completion must not create another settlement transaction.
    $lifecycle->confirmCompletion($booking->fresh(), $second->fresh(), (int) $customer->id);
    expect(CleaningDepositTransaction::query()
        ->where('worker_id', $worker->id)
        ->where('reference', 'like', CleaningDepositTransaction::AUTOMATIC_ADMIN_DEBT_REFERENCE_PREFIX.'%')
        ->count())->toBe(2);
});

it('stores independent reviews for the same worker across recurring sessions', function (): void {
    [$customer, $worker, $booking, $first, $second] = makeRecurringSessionInteractionContext();
    $lifecycle = app(CleaningBookingSessionLifecycleService::class);
    $interactions = app(CleaningBookingSessionInteractionService::class);

    $first = $lifecycle->confirmCompletion($booking->fresh(), $first->fresh(), (int) $customer->id);
    $second = $lifecycle->confirmCompletion($booking->fresh(), $second->fresh(), (int) $customer->id);

    $interactions->submitReview($booking->fresh(), $first, (int) $customer->id, [
        'workerId' => (int) $worker->id,
        'rating' => 5,
        'comment' => 'first visit',
    ]);
    $interactions->submitReview($booking->fresh(), $second, (int) $customer->id, [
        'workerId' => (int) $worker->id,
        'rating' => 4,
        'comment' => 'second visit',
    ]);

    expect(App\Models\WorkerCustomerRating::query()
        ->where('booking_id', $booking->id)
        ->where('worker_id', $worker->id)
        ->where('rating_type', WorkerCustomerRatingType::CustomerToWorker->value)
        ->count())->toBe(2)
        ->and($worker->fresh()->average_rating)->toEqual(4.5);
});

it('exposes per-session review dispute and payment state through the authenticated schedule contract', function (): void {
    [$customer, $worker, $booking, $first] = makeRecurringSessionInteractionContext();
    $first = app(CleaningBookingSessionLifecycleService::class)
        ->confirmCompletion($booking->fresh(), $first->fresh(), (int) $customer->id);

    Sanctum::actingAs($customer);
    postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$first->id}/review", [
        'workerId' => $worker->id,
        'rating' => 5,
        'comment' => 'great recurring visit',
    ])->assertOk()
        ->assertJsonPath('data.schedule.sessions.0.paymentStatus', 'settled')
        ->assertJsonPath('data.schedule.sessions.0.hasReview', true);

    postJson("/api/v1/cleaning-bookings/{$booking->id}/sessions/{$first->id}/disputes", [
        'description' => 'There is a billing question for this visit.',
        'category' => DisputeCategory::BillingIssue->value,
    ])->assertCreated()
        ->assertJsonPath('data.schedule.sessions.0.hasOpenDispute', true)
        ->assertJsonPath('data.schedule.sessions.0.canOpenDispute', false);

    $presented = app(CleaningBookingSchedulePresenter::class)->present($booking->fresh());
    expect($presented['sessions'][0]['reviewedWorkerIds'])->toContain((int) $worker->id)
        ->and($presented['sessions'][0]['payment']['isInternalSettlement'])->toBeTrue()
        ->and($presented['sessions'][0]['disputeStatus'])->toBe('open');
});

it('rejects a second active dispute for the same recurring session', function (): void {
    [$customer, , $booking, $first] = makeRecurringSessionInteractionContext();
    $first = app(CleaningBookingSessionLifecycleService::class)
        ->confirmCompletion($booking->fresh(), $first->fresh(), (int) $customer->id);
    $interactions = app(CleaningBookingSessionInteractionService::class);

    $interactions->openDispute(
        $booking->fresh(),
        $first,
        (int) $customer->id,
        'First dispute',
        DisputeCategory::PoorQuality,
    );

    expect(fn () => $interactions->openDispute(
        $booking->fresh(),
        $first,
        (int) $customer->id,
        'Duplicate dispute',
        DisputeCategory::Other,
    ))->toThrow(ValidationException::class);
});
