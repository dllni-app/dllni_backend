<?php

declare(strict_types=1);

use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Services\CleaningBookingFinancialExposureService;

function financialExposureBooking(float $customerTotal, float $adminMargin = 0): CleaningBooking
{
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'number_of_workers' => 1,
    ]);

    $details = $booking->property_details ?? [];
    $details['_time_period_v2'] = ['contractVersion' => 2, 'pricing' => ['totalPrice' => $customerTotal]];

    $booking->forceFill([
        'property_details' => $details,
        'total_price' => $customerTotal,
        'admin_margin_amount' => $adminMargin,
    ])->saveQuietly();

    return $booking->fresh();
}

it('flags an actual unfunded worker allowance without changing customer or worker amounts', function (): void {
    $booking = financialExposureBooking(150);
    $booking->workerAssignments()->create([
        'worker_id' => Worker::factory()->create()->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'service_share_amount' => 150,
        'travel_fee' => 10,
        'admin_margin_amount' => 0,
        'worker_amount' => 160,
        'currency' => 'SYP',
    ]);

    $before = $booking->fresh()->total_price;
    $exposure = app(CleaningBookingFinancialExposureService::class)->forBooking($booking->fresh());

    expect($exposure)->toMatchArray([
        'customerTotal' => 150.0,
        'workerPayable' => 160.0,
        'adminMargin' => 0.0,
        'gap' => 10.0,
        'source' => 'booking_assignments',
    ])->and((float) $booking->fresh()->total_price)->toBe((float) $before);
});

it('does not warn when customer amount covers worker payout and margin', function (): void {
    $booking = financialExposureBooking(180, 20);
    $booking->workerAssignments()->create([
        'worker_id' => Worker::factory()->create()->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'service_share_amount' => 150,
        'travel_fee' => 10,
        'admin_margin_amount' => 20,
        'worker_amount' => 160,
        'currency' => 'SYP',
    ]);

    expect(app(CleaningBookingFinancialExposureService::class)->forBooking($booking)['gap'])->toBe(0.0);
});

it('detects a legacy V2 order which promises a supervisor fee but allocated its full base to workers', function (): void {
    $booking = financialExposureBooking(3645, 725);
    $details = $booking->property_details;
    $details['_supervisor_pricing'] = [
        'fee' => 1400,
        'hourlyRate' => 400,
        'billableMinutes' => 210,
    ];
    $booking->forceFill(['property_details' => $details])->saveQuietly();
    $session = CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => 1,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 3.5,
        'required_workers' => 2,
        'status' => 'worker_assigned',
        'coverage_status' => 'fully_covered',
        'pricing_snapshot' => ['contractVersion' => 2],
    ]);
    foreach ([1, 2] as $seat) {
        $session->workerAssignments()->create([
            'worker_id' => Worker::factory()->create()->id,
            'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
            'worker_amount' => 1460,
            'service_share_amount' => 1450,
            'travel_fee' => 10,
            'currency' => 'SYP',
        ]);
    }

    expect(app(CleaningBookingFinancialExposureService::class)->forBooking($booking->fresh()))
        ->toMatchArray([
            'customerTotal' => 3645.0,
            'workerPayable' => 2920.0,
            'adminMargin' => 725.0,
            'supervisorFee' => 1400.0,
            'gap' => 1400.0,
            'source' => 'session_assignments',
        ]);
    expect((float) $booking->fresh()->total_price)->toBe(3645.0);
});

it('does not create a deficit when the quoted supervisor fee is funded separately from worker shares', function (): void {
    $booking = financialExposureBooking(3645, 725);
    $details = $booking->property_details;
    $details['_supervisor_pricing'] = ['fee' => 1400];
    $booking->forceFill(['property_details' => $details])->saveQuietly();
    foreach ([1, 2] as $seat) {
        $booking->workerAssignments()->create([
            'worker_id' => Worker::factory()->create()->id,
            'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
            'worker_amount' => 760,
            'service_share_amount' => 750,
            'travel_fee' => 10,
            'currency' => 'SYP',
        ]);
    }

    expect(app(CleaningBookingFinancialExposureService::class)->forBooking($booking->fresh()))
        ->toMatchArray(['workerPayable' => 1520.0, 'supervisorFee' => 1400.0, 'gap' => 0.0]);
});

it('uses only session assignments when a V2 booking has no accepted parent assignments', function (): void {
    $booking = financialExposureBooking(150);
    $session = CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => 1,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 1,
        'required_workers' => 1,
        'status' => 'worker_assigned',
        'coverage_status' => 'fully_covered',
    ]);
    $session->workerAssignments()->create([
        'worker_id' => Worker::factory()->create()->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'worker_amount' => 160,
        'service_share_amount' => 150,
        'travel_fee' => 10,
        'currency' => 'SYP',
    ]);

    expect(app(CleaningBookingFinancialExposureService::class)->forBooking($booking))
        ->toMatchArray(['gap' => 10.0, 'source' => 'session_assignments']);
});

it('does not mistake non-V2 bookings for the same accounting contract', function (): void {
    $booking = CleaningBooking::factory()->create();

    expect(app(CleaningBookingFinancialExposureService::class)->forBooking($booking))->toBeNull();
});
