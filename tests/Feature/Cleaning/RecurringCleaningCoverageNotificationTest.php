<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\BookingLifecycleNotification;
use Illuminate\Support\Facades\Notification;
use Modules\Cleaning\Enums\CleaningBookingSessionCoverageStatus;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningNotificationDispatch;
use Modules\Cleaning\Services\CleaningBookingSessionCoverageService;

it('sends deduplicated aggregate coverage snapshots for future recurring visits', function (): void {
    Notification::fake();

    $customer = User::factory()->create(['is_active' => true]);
    $workers = Worker::factory()->count(4)->create();
    $startsAt = now(config('app.timezone'))->addDays(2)->setTime(10, 0);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::Pending->value,
        'scheduled_date' => $startsAt->toDateString(),
        'scheduled_time' => $startsAt->format('H:i'),
        'estimated_hours' => 2,
        'total_hours' => 8,
        'base_price' => 4000,
        'addons_total' => 0,
        'admin_margin_amount' => 400,
        'total_price' => 4400,
    ]);

    $makeSession = static function (int $sequence, int $dayOffset) use ($booking, $startsAt): CleaningBookingSession {
        $scheduledAt = $startsAt->copy()->addDays($dayOffset);

        return CleaningBookingSession::query()->create([
            'cleaning_booking_id' => $booking->id,
            'sequence' => $sequence,
            'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
            'calculation_mode' => 'task',
            'scheduled_date' => $scheduledAt->toDateString(),
            'scheduled_time' => $scheduledAt->format('H:i'),
            'duration_hours' => 2,
            'required_workers' => 2,
            'coverage_status' => CleaningBookingSessionCoverageStatus::Searching,
            'status' => CleaningBookingSessionStatus::Scheduled,
            'base_price' => 2000,
            'addons_total' => 0,
            'materials_total' => 0,
            'special_services_total' => 0,
            'travel_fee' => 0,
            'admin_margin_amount' => 200,
            'extension_fee_total' => 0,
            'cancellation_fee' => 0,
            'total_price' => 2200,
            'is_pricing_final' => false,
        ]);
    };

    $firstSession = $makeSession(1, 0);
    $secondSession = $makeSession(2, 2);
    $coverage = app(CleaningBookingSessionCoverageService::class);

    foreach ($workers->take(2) as $worker) {
        $firstSession->workerAssignments()->create([
            'worker_id' => $worker->id,
            'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
            'accepted_at' => now(),
        ]);
    }

    $coverage->refresh($firstSession);
    $coverage->refresh($firstSession->fresh());

    Notification::assertSentTo(
        $customer,
        BookingLifecycleNotification::class,
        function (BookingLifecycleNotification $notification) use ($customer): bool {
            $payload = $notification->toArray($customer);

            return ($payload['canonical_type'] ?? null) === 'cleaning.booking.recurring_coverage_progress'
                && data_get($payload, 'data.coveredSessions') === 1
                && data_get($payload, 'data.totalSessions') === 2
                && data_get($payload, 'data.remainingSeats') === 2;
        },
    );

    expect(CleaningNotificationDispatch::query()
        ->where('cleaning_booking_id', $booking->id)
        ->where('canonical_type', 'cleaning.booking.recurring_coverage_progress')
        ->count())->toBe(1);

    foreach ($workers->skip(2) as $worker) {
        $secondSession->workerAssignments()->create([
            'worker_id' => $worker->id,
            'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
            'accepted_at' => now(),
        ]);
    }

    $coverage->refresh($secondSession);
    $coverage->refresh($secondSession->fresh());

    Notification::assertSentTo(
        $customer,
        BookingLifecycleNotification::class,
        function (BookingLifecycleNotification $notification) use ($customer): bool {
            $payload = $notification->toArray($customer);

            return ($payload['canonical_type'] ?? null) === 'cleaning.booking.recurring_coverage_complete'
                && data_get($payload, 'data.coveredSessions') === 2
                && data_get($payload, 'data.totalSessions') === 2
                && data_get($payload, 'data.remainingSeats') === 0;
        },
    );

    expect(CleaningNotificationDispatch::query()
        ->where('cleaning_booking_id', $booking->id)
        ->whereIn('canonical_type', [
            'cleaning.booking.recurring_coverage_progress',
            'cleaning.booking.recurring_coverage_complete',
        ])
        ->count())->toBe(2);
});
