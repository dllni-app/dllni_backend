<?php

declare(strict_types=1);

use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Services\CleaningBookingSchedulePresenter;
use Modules\Cleaning\Services\CleaningBookingSessionFinancialAggregationService;
use Modules\Cleaning\Services\CleaningBookingSessionWorkerPricingService;

it('aggregates active multi-day session financials while preserving completed history', function (): void {
    $booking = makeFinancialSettlementBooking();
    makeFinancialSettlementSession(
        $booking,
        sequence: 1,
        status: CleaningBookingSessionStatus::Completed,
        basePrice: 1000,
        adminMargin: 200,
        travelFee: 50,
        extensionFee: 0,
        totalPrice: 1250,
    );
    makeFinancialSettlementSession(
        $booking,
        sequence: 2,
        status: CleaningBookingSessionStatus::WorkerAssigned,
        basePrice: 2000,
        adminMargin: 400,
        travelFee: 60,
        extensionFee: 100,
        totalPrice: 2560,
    );
    makeFinancialSettlementSession(
        $booking,
        sequence: 3,
        status: CleaningBookingSessionStatus::Scheduled,
        basePrice: 1500,
        adminMargin: 300,
        travelFee: 40,
        extensionFee: 0,
        totalPrice: 1840,
    );

    app(CleaningBookingSessionFinancialAggregationService::class)->sync($booking);

    $booking = $booking->fresh();
    expect((float) $booking->base_price)->toBe(4500.0)
        ->and((float) $booking->admin_margin_amount)->toBe(900.0)
        ->and((float) $booking->travel_fee)->toBe(150.0)
        ->and((float) $booking->extension_fee_total)->toBe(100.0)
        ->and((float) $booking->cancellation_fee)->toBe(0.0)
        ->and((float) $booking->total_hours)->toBe(6.0)
        ->and((float) $booking->total_price)->toBe(5650.0);
});

it('excludes cancelled skipped and superseded service values while retaining cancellation fees separately', function (): void {
    $booking = makeFinancialSettlementBooking();
    makeFinancialSettlementSession(
        $booking,
        sequence: 1,
        status: CleaningBookingSessionStatus::WorkerAssigned,
        basePrice: 1000,
        adminMargin: 200,
        totalPrice: 1200,
    );
    makeFinancialSettlementSession(
        $booking,
        sequence: 2,
        status: CleaningBookingSessionStatus::Cancelled,
        basePrice: 2000,
        adminMargin: 400,
        cancellationFee: 250,
        totalPrice: 2400,
    );
    makeFinancialSettlementSession(
        $booking,
        sequence: 3,
        status: CleaningBookingSessionStatus::Skipped,
        basePrice: 3000,
        adminMargin: 600,
        totalPrice: 3600,
    );
    makeFinancialSettlementSession(
        $booking,
        sequence: 4,
        status: CleaningBookingSessionStatus::Superseded,
        basePrice: 4000,
        adminMargin: 800,
        totalPrice: 4800,
    );

    app(CleaningBookingSessionFinancialAggregationService::class)->sync($booking);

    $booking = $booking->fresh();
    expect((float) $booking->base_price)->toBe(1000.0)
        ->and((float) $booking->admin_margin_amount)->toBe(200.0)
        ->and((float) $booking->cancellation_fee)->toBe(250.0)
        ->and((float) $booking->total_hours)->toBe(2.0)
        ->and((float) $booking->total_price)->toBe(1450.0);
});

it('isolates an extension fee to the target session and changes the parent only by that delta', function (): void {
    $booking = makeFinancialSettlementBooking();
    $first = makeFinancialSettlementSession(
        $booking,
        sequence: 1,
        status: CleaningBookingSessionStatus::WorkerAssigned,
        basePrice: 1000,
        adminMargin: 200,
        totalPrice: 1200,
    );
    $second = makeFinancialSettlementSession(
        $booking,
        sequence: 2,
        status: CleaningBookingSessionStatus::WorkerAssigned,
        basePrice: 1000,
        adminMargin: 200,
        totalPrice: 1200,
    );
    $third = makeFinancialSettlementSession(
        $booking,
        sequence: 3,
        status: CleaningBookingSessionStatus::WorkerAssigned,
        basePrice: 1000,
        adminMargin: 200,
        totalPrice: 1200,
    );

    $aggregation = app(CleaningBookingSessionFinancialAggregationService::class);
    $aggregation->sync($booking);
    $before = (float) $booking->fresh()->total_price;

    $second->forceFill([
        'extension_fee_total' => 180,
        'total_price' => 1380,
    ])->save();
    $aggregation->sync($booking);

    expect((float) $first->fresh()->extension_fee_total)->toBe(0.0)
        ->and((float) $first->fresh()->total_price)->toBe(1200.0)
        ->and((float) $second->fresh()->extension_fee_total)->toBe(180.0)
        ->and((float) $second->fresh()->total_price)->toBe(1380.0)
        ->and((float) $third->fresh()->extension_fee_total)->toBe(0.0)
        ->and((float) $third->fresh()->total_price)->toBe(1200.0)
        ->and((float) $booking->fresh()->extension_fee_total)->toBe(180.0)
        ->and((float) $booking->fresh()->total_price)->toBe($before + 180.0);
});

it('allocates multi-worker session entitlement by seat without deducting admin margin twice', function (): void {
    $booking = makeFinancialSettlementBooking(['number_of_workers' => 2]);
    $session = makeFinancialSettlementSession(
        $booking,
        sequence: 1,
        status: CleaningBookingSessionStatus::WorkerAssigned,
        basePrice: 1000,
        adminMargin: 200,
        totalPrice: 1200,
        requiredWorkers: 2,
    );
    $firstWorker = Worker::factory()->create([
        'home_address' => 'Worker A Home',
        'home_latitude' => 36.20,
        'home_longitude' => 37.15,
    ]);
    $secondWorker = Worker::factory()->create([
        'home_address' => 'Worker B Home',
        'home_latitude' => 36.22,
        'home_longitude' => 37.17,
    ]);
    $pricing = app(CleaningBookingSessionWorkerPricingService::class);

    $first = $pricing->quoteForNextSeat($session, $firstWorker, 0);
    $second = $pricing->quoteForNextSeat($session, $secondWorker, 1);

    expect($first['serviceShareAmount'])->toBe(500.0)
        ->and($second['serviceShareAmount'])->toBe(500.0)
        ->and($first['adminMarginAmount'])->toBe(100.0)
        ->and($second['adminMarginAmount'])->toBe(100.0)
        ->and($first['workerAmount'])->toBe($first['serviceShareAmount'] + $first['travelFee'])
        ->and($second['workerAmount'])->toBe($second['serviceShareAmount'] + $second['travelFee'])
        ->and(round($first['serviceShareAmount'] + $second['serviceShareAmount'], 2))->toBe(1000.0);
});

it('keeps released worker payout as audit history but exposes only payable replacement assignments operationally', function (): void {
    $booking = makeFinancialSettlementBooking();
    $session = makeFinancialSettlementSession(
        $booking,
        sequence: 1,
        status: CleaningBookingSessionStatus::WorkerAssigned,
        basePrice: 1000,
        adminMargin: 200,
        totalPrice: 1200,
    );
    $releasedWorker = Worker::factory()->create();
    $replacementWorker = Worker::factory()->create();

    makeFinancialSettlementAssignment(
        $session,
        $releasedWorker,
        CleaningBookingWorkerAssignmentStatus::Cancelled,
        workerAmount: 500,
        released: true,
    );
    makeFinancialSettlementAssignment(
        $session,
        $replacementWorker,
        CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
        workerAmount: 700,
    );

    $schedule = app(CleaningBookingSchedulePresenter::class)->present($booking);
    $assignments = collect($schedule['sessions'][0]['workerAssignments']);

    expect($session->fresh()->workerAssignments()->count())->toBe(2)
        ->and($assignments)->toHaveCount(1)
        ->and((int) $assignments->first()['workerId'])->toBe((int) $replacementWorker->id)
        ->and((float) $assignments->first()['workerAmount'])->toBe(700.0)
        ->and($assignments->pluck('workerId')->map(fn ($id): int => (int) $id)->all())
        ->not->toContain((int) $releasedWorker->id);
});

it('applies a booking-level discount exactly once when session aggregates are resynchronized', function (): void {
    $booking = makeFinancialSettlementBooking([
        'discount_amount' => 100,
        'subtotal_before_discount' => 1200,
    ]);
    $first = makeFinancialSettlementSession(
        $booking,
        sequence: 1,
        status: CleaningBookingSessionStatus::WorkerAssigned,
        basePrice: 500,
        adminMargin: 100,
        totalPrice: 600,
    );
    $second = makeFinancialSettlementSession(
        $booking,
        sequence: 2,
        status: CleaningBookingSessionStatus::WorkerAssigned,
        basePrice: 500,
        adminMargin: 100,
        totalPrice: 600,
    );

    $aggregation = app(CleaningBookingSessionFinancialAggregationService::class);
    $aggregation->sync($booking);
    $firstSync = $booking->fresh();
    $aggregation->sync($firstSync);
    $secondSync = $booking->fresh();

    expect((float) $firstSync->subtotal_before_discount)->toBe(1200.0)
        ->and((float) $firstSync->discount_amount)->toBe(100.0)
        ->and((float) $firstSync->total_price)->toBe(1100.0)
        ->and((float) $secondSync->subtotal_before_discount)->toBe(1200.0)
        ->and((float) $secondSync->discount_amount)->toBe(100.0)
        ->and((float) $secondSync->total_price)->toBe(1100.0)
        ->and((float) $first->fresh()->total_price)->toBe(600.0)
        ->and((float) $second->fresh()->total_price)->toBe(600.0);
});

function makeFinancialSettlementBooking(array $overrides = []): CleaningBooking
{
    return CleaningBooking::factory()->create([
        'property_type' => 'event_assistance',
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'number_of_workers' => 1,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'estimated_hours' => 2,
        'total_hours' => 0,
        'base_price' => 0,
        'addons_total' => 0,
        'extension_fee_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'cancellation_fee' => 0,
        'discount_amount' => 0,
        'subtotal_before_discount' => null,
        'total_price' => 0,
        'address' => 'Financial Settlement Test Address',
        'address_latitude' => 36.21,
        'address_longitude' => 37.16,
        ...$overrides,
    ]);
}

function makeFinancialSettlementSession(
    CleaningBooking $booking,
    int $sequence,
    CleaningBookingSessionStatus $status,
    float $basePrice,
    float $adminMargin,
    float $totalPrice,
    float $travelFee = 0,
    float $extensionFee = 0,
    float $cancellationFee = 0,
    float $hours = 2,
    int $requiredWorkers = 1,
): CleaningBookingSession {
    return CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => $sequence,
        'session_type' => 'event_assistance',
        'calculation_mode' => 'hours',
        'scheduled_date' => now()->addDays($sequence)->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => $hours,
        'required_workers' => $requiredWorkers,
        'coverage_status' => in_array($status, [
            CleaningBookingSessionStatus::Scheduled,
            CleaningBookingSessionStatus::Skipped,
            CleaningBookingSessionStatus::Superseded,
        ], true)
            ? CleaningBookingSessionCoverageStatus::Searching
            : CleaningBookingSessionCoverageStatus::FullyCovered,
        'status' => $status,
        'base_price' => $basePrice,
        'addons_total' => 0,
        'materials_total' => 0,
        'special_services_total' => 0,
        'travel_fee' => $travelFee,
        'admin_margin_amount' => $adminMargin,
        'extension_fee_total' => $extensionFee,
        'cancellation_fee' => $cancellationFee,
        'total_price' => $totalPrice,
        'is_pricing_final' => true,
        'work_started_at' => $status === CleaningBookingSessionStatus::Completed
            ? now()->subHours(3)
            : null,
        'work_finished_at' => $status === CleaningBookingSessionStatus::Completed
            ? now()->subHour()
            : null,
    ]);
}

function makeFinancialSettlementAssignment(
    CleaningBookingSession $session,
    Worker $worker,
    CleaningBookingWorkerAssignmentStatus $status,
    float $workerAmount,
    bool $released = false,
): CleaningBookingSessionWorkerAssignment {
    return CleaningBookingSessionWorkerAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'worker_id' => $worker->id,
        'status' => $status,
        'accepted_at' => now()->subHour(),
        'released_at' => $released ? now()->subMinutes(30) : null,
        'released_reason' => $released ? 'Replaced for test' : null,
        'service_share_amount' => $workerAmount,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => $workerAmount,
        'currency' => 'SYP',
    ]);
}
