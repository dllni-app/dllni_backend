<?php

declare(strict_types=1);

use App\Models\Worker;
use App\Models\User;
use App\Models\CleaningFinancialSetting;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
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

it('reserves an explicitly funded supervisor fee outside both worker seats without rewriting historical sessions', function (): void {
    $booking = makeFinancialSettlementBooking(['number_of_workers' => 2]);
    $session = makeFinancialSettlementSession(
        $booking, 1, CleaningBookingSessionStatus::WorkerAssigned, 2900, 725, 3645,
        travelFee: 20, requiredWorkers: 2,
    );
    $workers = [Worker::factory()->create(), Worker::factory()->create()];
    $service = app(CleaningBookingSessionWorkerPricingService::class);

    // Existing bookings lacking a verifiable included-fee breakdown are left intact.
    expect($service->quoteForNextSeat($session, $workers[0], 0)['serviceShareAmount'])->toBe(1450.0);

    $session->forceFill(['pricing_snapshot' => ['contractVersion' => 2, 'supervisorFeeIncluded' => 1400]])->saveQuietly();
    $first = $service->quoteForNextSeat($session->fresh(), $workers[0], 0);
    $second = $service->quoteForNextSeat($session->fresh(), $workers[1], 1);
    expect($first['serviceShareAmount'])->toBe(750.0)
        ->and($second['serviceShareAmount'])->toBe(750.0)
        ->and($first['serviceShareAmount'] + $second['serviceShareAmount'] + 1400)->toBe(2900.0);
});

it('keeps a fixed transport allowance even when worker coordinates are absent', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'default_commission_rate' => 0,
        'travel_markup_type' => 'worker_allowance',
        'travel_markup_value' => 10,
    ]);
    $booking = makeFinancialSettlementBooking([
        'number_of_workers' => 2,
        'address_latitude' => null,
        'address_longitude' => null,
    ]);
    $session = makeFinancialSettlementSession(
        $booking, 1, CleaningBookingSessionStatus::WorkerAssigned, 1000, 200, 1220,
        requiredWorkers: 2,
    );
    $worker = Worker::factory()->create([
        'home_address' => null,
        'home_latitude' => null,
        'home_longitude' => null,
    ]);

    $quote = app(CleaningBookingSessionWorkerPricingService::class)->quoteForNextSeat($session, $worker, 0);

    expect($quote['serviceShareAmount'])->toBe(500.0)
        ->and($quote['travelFee'])->toBe(10.0)
        ->and($quote['workerAmount'])->toBe(510.0);
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

it('shows only the viewing workers session payout and conceals customer session billing', function (): void {
    $booking = makeFinancialSettlementBooking();
    $session = makeFinancialSettlementSession($booking, 1, CleaningBookingSessionStatus::WorkerAssigned, 1500, 250, 1750, requiredWorkers: 2);
    $firstWorker = Worker::factory()->create();
    $otherWorker = Worker::factory()->create();
    makeFinancialSettlementAssignment($session, $firstWorker, CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart, 500);
    makeFinancialSettlementAssignment($session, $otherWorker, CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart, 750);

    $workerSchedule = app(CleaningBookingSchedulePresenter::class)->present($booking, $firstWorker);
    $row = $workerSchedule['sessions'][0];
    expect($row['workerAssignments'])->toHaveCount(1)
        ->and($row['workerAssignments'][0]['workerId'])->toBe($firstWorker->id)
        ->and($row['workerAssignments'][0]['workerAmount'])->toEqual(500)
        ->and($row['myAssignment']['workerAmount'])->toEqual(500)
        ->and($row['pricing'])->toBeNull()
        ->and($row['payment'])->toBeNull()
        ->and($row['openTime'])->toBeNull();

    $customerSchedule = app(CleaningBookingSchedulePresenter::class)->present($booking);
    expect($customerSchedule['sessions'][0]['workerAssignments'])->toHaveCount(2)
        ->and($customerSchedule['sessions'][0]['pricing']['totalPrice'])->toEqual(1750);
});

it('uses accepted session payouts instead of a zero parent offer in worker financial summaries', function (): void {
    $booking = makeFinancialSettlementBooking();
    $session = makeFinancialSettlementSession($booking, 1, CleaningBookingSessionStatus::Completed, 1000, 200, 1200);
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    makeFinancialSettlementAssignment($session, $worker, CleaningBookingWorkerAssignmentStatus::Completed, 800);
    $otherWorker = Worker::factory()->create();
    makeFinancialSettlementAssignment($session, $otherWorker, CleaningBookingWorkerAssignmentStatus::Completed, 300);

    Sanctum::actingAs($workerUser);
    $workerRequest = request();
    $workerRequest->setUserResolver(static fn () => $workerUser);
    $payload = CleaningBookingResource::make($booking)->resolve($workerRequest);
    expect($payload['workerFinancials']['workerAmount'])->toBe(800.0)
        ->and($payload['workerFinancials']['serviceShareAmount'])->toBe(800.0)
        ->and($payload['workerFinancials']['isPreview'])->toBeFalse();
});

it('shows the customer service price with its booking-wide margin alongside a worker share', function (): void {
    $booking = makeFinancialSettlementBooking([
        'number_of_workers' => 2,
        'base_price' => 2900,
        'addons_total' => 0,
        'travel_fee' => 20,
        'admin_margin_amount' => 725,
        'total_price' => 3645,
    ]);
    $session = makeFinancialSettlementSession(
        $booking, 1, CleaningBookingSessionStatus::WorkerAssigned,
        2900, 725, 3645, travelFee: 20, requiredWorkers: 2,
    );
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $assignment = makeFinancialSettlementAssignment(
        $session, $worker, CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart, 1460,
    );
    $assignment->forceFill([
        'service_share_amount' => 1450,
        'admin_margin_amount' => 362.5,
        'travel_fee' => 10,
        'worker_amount' => 1460,
    ])->saveQuietly();

    Sanctum::actingAs($workerUser);
    $request = request();
    $request->setUserResolver(static fn () => $workerUser);
    $financials = CleaningBookingResource::make($booking->fresh())->resolve($request)['workerFinancials'];

    expect($financials['bookingBaseServiceAmount'])->toBe(2900.0)
        ->and($financials['bookingServicePriceIncludingAdmin'])->toBe(3625.0)
        ->and($financials['bookingAdminMarginAmount'])->toBe(725.0)
        ->and($financials['serviceShareAmount'])->toBe(1450.0)
        ->and($financials['adminMarginAmount'])->toBe(362.5)
        ->and($financials['workerAmount'])->toBe(1460.0);

    // Minimum-price-inclusive commission is not added again, and coupon
    // reductions must follow the persisted customer total, not a fresh quote.
    $booking->forceFill(['base_price' => 1500, 'admin_margin_amount' => 375, 'travel_fee' => 0, 'total_price' => 1500])->saveQuietly();
    $included = CleaningBookingResource::make($booking->fresh())->resolve($request)['workerFinancials'];
    expect($included['bookingServicePriceIncludingAdmin'])->toBe(1500.0)
        ->and($included['bookingAdminMarginAmount'])->toBe(375.0);

    $booking->forceFill(['base_price' => 2900, 'admin_margin_amount' => 725, 'travel_fee' => 20, 'total_price' => 3445, 'discount_amount' => 200])->saveQuietly();
    $discounted = CleaningBookingResource::make($booking->fresh())->resolve($request)['workerFinancials'];
    expect($discounted['bookingServicePriceIncludingAdmin'])->toBe(3425.0);
});

it('marks worker distribution final only after all payable sessions are settled, even with fixed travel', function (): void {
    $booking = makeFinancialSettlementBooking([
        'number_of_workers' => 2,
        'property_details' => ['_time_period_v2' => ['contractVersion' => 2, 'rooms' => [], 'visits' => []]],
    ]);
    $first = makeFinancialSettlementSession($booking, 1, CleaningBookingSessionStatus::Completed, 1000, 200, 1220, travelFee: 20, requiredWorkers: 2);
    $second = makeFinancialSettlementSession($booking, 2, CleaningBookingSessionStatus::WorkerAssigned, 1000, 200, 1220, travelFee: 20, requiredWorkers: 2);
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $firstAssignment = makeFinancialSettlementAssignment($first, $worker, CleaningBookingWorkerAssignmentStatus::Completed, 510);
    $secondAssignment = makeFinancialSettlementAssignment($second, $worker, CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart, 510);
    foreach ([$firstAssignment, $secondAssignment] as $assignment) {
        $assignment->forceFill(['service_share_amount' => 500, 'travel_fee' => 10, 'worker_amount' => 510])->saveQuietly();
    }
    $first->forceFill(['payment_status' => 'settled', 'payment_settled_at' => now()])->saveQuietly();

    Sanctum::actingAs($workerUser);
    $request = request();
    $request->setUserResolver(static fn () => $workerUser);
    $financials = CleaningBookingResource::make($booking->fresh())->resolve($request)['workerFinancials'];
    expect($financials['isFinal'])->toBeFalse()
        ->and($financials['travelFee'])->toBe(20.0)
        ->and($financials['workerAmount'])->toBe(1020.0);

    $second->forceFill([
        'status' => CleaningBookingSessionStatus::Completed,
        'payment_status' => 'settled',
        'payment_settled_at' => now(),
    ])->saveQuietly();
    $secondAssignment->forceFill(['status' => CleaningBookingWorkerAssignmentStatus::Completed])->saveQuietly();
    $financials = CleaningBookingResource::make($booking->fresh())->resolve($request)['workerFinancials'];
    expect($financials['isFinal'])->toBeTrue()
        ->and($financials['isPricingFinal'])->toBeTrue()
        ->and($financials['isPreview'])->toBeFalse()
        ->and($financials['travelFee'])->toBe(20.0)
        ->and($financials['workerAmount'])->toBe(1020.0);
});

it('splits V2 projected worker earnings into base special and materials from session snapshots', function (): void {
    $booking = makeFinancialSettlementBooking(['number_of_workers' => 2, 'property_details' => ['_time_period_v2' => ['contractVersion' => 2, 'rooms' => [], 'visits' => []]]]);
    $session = makeFinancialSettlementSession($booking, 1, CleaningBookingSessionStatus::WorkerAssigned, 800, 100, 1100);
    $session->forceFill(['required_workers' => 2, 'addons_total' => 200, 'special_services_total' => 150, 'materials_total' => 50])->saveQuietly();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    makeFinancialSettlementAssignment($session, $worker, CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart, 500);

    Sanctum::actingAs($workerUser);
    $request = request();
    $request->setUserResolver(static fn () => $workerUser);
    $financials = CleaningBookingResource::make($booking->fresh())->resolve($request)['workerFinancials'];

    expect($financials['baseServiceShareAmount'])->toBe(400.0)
        ->and($financials['serviceAndOperationalShareAmount'])->toBe(425.0)
        ->and($financials['specialServicesShareAmount'])->toBe(75.0)
        ->and(array_key_exists('materialsShareAmount', $financials))->toBeFalse()
        ->and($financials['serviceShareAmount'])->toBe(500.0)
        ->and($financials['plannedWorkerCount'])->toBe(2)
        ->and($financials['isFinal'])->toBeFalse()
        ->and($financials['isPricingFinal'])->toBeFalse()
        ->and($financials['isPreview'])->toBeTrue();
});

it('splits worker-only V2 earnings without allocating unpaid operational materials', function (): void {
    $booking = makeFinancialSettlementBooking(['number_of_workers' => 2, 'property_details' => ['_time_period_v2' => ['contractVersion' => 2, 'rooms' => [], 'visits' => []]]]);
    $session = makeFinancialSettlementSession($booking, 1, CleaningBookingSessionStatus::WorkerAssigned, 1450, 300, 2025);
    $session->forceFill([
        'required_workers' => 2,
        'addons_total' => 275,
        'special_services_total' => 200,
        'materials_total' => 75,
    ])->saveQuietly();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $assignment = makeFinancialSettlementAssignment($session, $worker, CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart, 835);
    $assignment->forceFill([
        'service_share_amount' => 825,
        'travel_fee' => 10,
        'worker_amount' => 835,
    ])->saveQuietly();

    Sanctum::actingAs($workerUser);
    $request = request();
    $request->setUserResolver(static fn () => $workerUser);
    $financials = CleaningBookingResource::make($booking->fresh())->resolve($request)['workerFinancials'];

    expect($financials['baseServiceShareAmount'])->toBe(725.0)
        ->and($financials['specialServicesShareAmount'])->toBe(100.0)
        ->and($financials['serviceShareAmount'])->toBe(825.0)
        ->and($financials['workerAmount'])->toBe(835.0)
        ->and($financials['serviceAndOperationalShareAmount'])->toBe(725.0)
        ->and(array_key_exists('materialsShareAmount', $financials))->toBeFalse();
});

it('does not invent a V2 special service share when extras lack a reconciled source', function (): void {
    $booking = makeFinancialSettlementBooking(['number_of_workers' => 2, 'property_details' => ['_time_period_v2' => ['contractVersion' => 2, 'rooms' => [], 'visits' => []]]]);
    $session = makeFinancialSettlementSession($booking, 1, CleaningBookingSessionStatus::WorkerAssigned, 800, 100, 1100);
    $session->forceFill(['addons_total' => 200, 'special_services_total' => 150, 'materials_total' => 0])->saveQuietly();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    makeFinancialSettlementAssignment($session, $worker, CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart, 500);

    Sanctum::actingAs($workerUser);
    $request = request();
    $request->setUserResolver(static fn () => $workerUser);
    $financials = CleaningBookingResource::make($booking->fresh())->resolve($request)['workerFinancials'];

    expect($financials['baseServiceShareAmount'])->toBeNull()
        ->and($financials['serviceAndOperationalShareAmount'])->toBeNull()
        ->and($financials['specialServicesShareAmount'])->toBeNull()
        ->and(array_key_exists('materialsShareAmount', $financials))->toBeFalse()
        ->and($financials['serviceShareAmount'])->toBe(500.0)
        ->and($financials['isFinal'])->toBeFalse();
});

it('keeps the price of materials visible in a solo worker financial projection', function (): void {
    $booking = makeFinancialSettlementBooking(['number_of_workers' => 1]);
    $session = makeFinancialSettlementSession($booking, 1, CleaningBookingSessionStatus::WorkerAssigned, 800, 100, 1100);
    $session->forceFill(['required_workers' => 1, 'addons_total' => 200, 'special_services_total' => 150, 'materials_total' => 50])->saveQuietly();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    makeFinancialSettlementAssignment($session, $worker, CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart, 1000);

    Sanctum::actingAs($workerUser);
    $request = request();
    $request->setUserResolver(static fn () => $workerUser);
    $financials = CleaningBookingResource::make($booking->fresh())->resolve($request)['workerFinancials'];
    expect($financials['baseServiceShareAmount'])->toBe(800.0)
        ->and($financials['specialServicesShareAmount'])->toBe(150.0)
        ->and($financials['materialsShareAmount'])->toBe(50.0)
        ->and($financials['workerAmount'])->toBe(1000.0);
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
