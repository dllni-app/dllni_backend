<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningFinancialSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningBookingSessionAcceptanceService;

beforeEach(function (): void {
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 0,
            'restriction_threshold_percent' => 100,
            'allowance_warning_threshold_percent' => 10,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 50,
        ],
    );

    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => CleaningFinancialSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'default_commission_rate' => 10.00,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'vat_rate' => 0.00,
            'travel_markup_type' => 'fixed',
            'travel_markup_value' => 0.00,
            'travel_per_km' => 0.00,
            'travel_distance_start_point' => 'worker_home',
        ],
    );
});

it('does not silently partially accept accept-all when selected sessions overlap', function (): void {
    $worker = makeSessionWorker();
    $booking = makeSessionBooking();

    $first = makeExecutionSession($booking, 1, now()->addDay()->toDateString(), '10:00', 2.0);
    $second = makeExecutionSession($booking, 2, now()->addDay()->toDateString(), '11:00', 2.0);

    $result = app(CleaningBookingSessionAcceptanceService::class)
        ->acceptAllAvailableSessions($booking, $worker);

    expect($result['allAccepted'])->toBeFalse()
        ->and($result['acceptedSessionIds'])->toBe([])
        ->and(collect($result['rejected'])->pluck('reasonCode')->all())
        ->toContain('selected_sessions_overlap')
        ->and($first->workerAssignments()->count())->toBe(0)
        ->and($second->workerAssignments()->count())->toBe(0);
});

it('keeps the final worker seat exclusive and leaves no over-assignment', function (): void {
    $firstWorker = makeSessionWorker();
    $secondWorker = makeSessionWorker();
    $booking = makeSessionBooking();
    $session = makeExecutionSession($booking, 1, now()->addDay()->toDateString(), '10:00', 2.0, 1);

    $firstResult = app(CleaningBookingSessionAcceptanceService::class)
        ->acceptSelectedSessions($booking, $firstWorker, [$session->id]);

    $secondResult = app(CleaningBookingSessionAcceptanceService::class)
        ->acceptSelectedSessions($booking, $secondWorker, [$session->id]);

    expect($firstResult['acceptedSessionIds'])->toBe([(int) $session->id])
        ->and($secondResult['acceptedSessionIds'])->toBe([])
        ->and(collect($secondResult['rejected'])->pluck('reasonCode')->all())
        ->toContain('session_fully_covered')
        ->and($session->workerAssignments()->whereIn('status', [
            'accepted',
            'accepted_waiting_for_order_start',
            'awaiting_start_verification',
            'start_approved',
            'in_progress',
            'awaiting_customer_completion',
            'time_extension_requested',
            'completed',
        ])->count())->toBe(1)
        ->and($session->fresh()->coverage_status->value)->toBe('fully_covered');
});

it('accepts only the explicitly selected sessions and keeps the others available', function (): void {
    $worker = makeSessionWorker();
    $booking = makeSessionBooking();

    $first = makeExecutionSession($booking, 1, now()->addDay()->toDateString(), '10:00', 1.0);
    $second = makeExecutionSession($booking, 2, now()->addDays(2)->toDateString(), '10:00', 1.0);
    $third = makeExecutionSession($booking, 3, now()->addDays(3)->toDateString(), '10:00', 1.0);

    $result = app(CleaningBookingSessionAcceptanceService::class)
        ->acceptSelectedSessions($booking, $worker, [$first->id, $third->id]);

    expect($result['rejected'])->toBe([])
        ->and($result['acceptedSessionIds'])->toBe([(int) $first->id, (int) $third->id])
        ->and($first->fresh()->coverage_status->value)->toBe('fully_covered')
        ->and($second->fresh()->coverage_status->value)->toBe('searching')
        ->and($third->fresh()->coverage_status->value)->toBe('fully_covered')
        ->and($second->workerAssignments()->count())->toBe(0);
});

function makeSessionWorker(): Worker
{
    $workingHours = [];
    foreach (['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'] as $day) {
        $workingHours[$day] = [
            'available' => true,
            'data' => [['00:00' => '23:59']],
        ];
    }

    $user = User::factory()->create(['is_active' => true]);
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'gender' => 'male',
        'trust_score' => 90,
        'is_active' => true,
        'is_suspended' => false,
        'home_address' => 'Worker Home',
        'home_latitude' => 36.20,
        'home_longitude' => 37.15,
        'default_working_hours' => $workingHours,
        'security_deposit_status' => 'active',
    ]);

    CleaningWorkerDeposit::query()->updateOrCreate(
        ['worker_id' => $worker->id],
        [
            'current_balance' => 100000,
            'debt_balance' => 0,
            'deposited_total' => 100000,
            'withdrawn_total' => 0,
            'minimum_required' => 0,
            'max_negative_balance' => 100000,
            'is_active' => true,
        ],
    );

    return $worker->fresh(['user', 'deposit']);
}

function makeSessionBooking(): CleaningBooking
{
    return CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'base_price' => 3000,
        'addons_total' => 0,
        'property_type' => 'event_assistance',
        'number_of_workers' => 1,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'gender_preference' => 'any',
        'neighborhood_id' => null,
        'address_latitude' => 36.1795,
        'address_longitude' => 37.1082,
    ]);
}

function makeExecutionSession(
    CleaningBooking $booking,
    int $sequence,
    string $date,
    string $time,
    float $hours,
    int $requiredWorkers = 1,
): CleaningBookingSession {
    return CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => $sequence,
        'session_type' => 'event_day',
        'calculation_mode' => 'hours',
        'scheduled_date' => $date,
        'scheduled_time' => $time,
        'duration_hours' => $hours,
        'required_workers' => $requiredWorkers,
        'coverage_status' => 'searching',
        'status' => 'scheduled',
        'base_price' => 3000,
        'admin_margin_amount' => 300,
        'total_price' => 3300,
        'is_pricing_final' => false,
    ]);
}
