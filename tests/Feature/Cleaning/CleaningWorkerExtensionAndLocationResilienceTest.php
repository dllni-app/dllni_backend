<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use App\Notifications\Cleaning\BookingLifecycleNotification;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Enums\CleaningTimeWarningResponse;
use Modules\Cleaning\Events\CompletionDecisionMade;
use Modules\Cleaning\Events\WorkerLocationUpdated;
use Modules\Cleaning\Models\CleaningBillingPolicy;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Services\CleaningTimeWarningService;

beforeEach(function (): void {
    $this->billingPolicy = CleaningBillingPolicy::query()->first()
        ?? CleaningBillingPolicy::query()->create([
            'name' => 'Default',
            'billing_mode' => 'actual_working_time',
            'rules' => [],
            'is_active' => true,
            'is_default' => true,
        ]);
});

it('treats repeated extension acceptance as an idempotent success', function (): void {
    Event::fake([CompletionDecisionMade::class]);

    $primaryUser = User::factory()->create(['email' => 'extension-primary@example.com']);
    $primaryWorker = Worker::factory()->financiallyEligible()->create(['user_id' => $primaryUser->id]);
    $respondingUser = User::factory()->create(['email' => 'extension-responder@example.com']);
    $respondingWorker = Worker::factory()->financiallyEligible()->create(['user_id' => $respondingUser->id]);
    $customer = User::factory()->create(['email' => 'extension-customer@example.com']);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $primaryWorker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested,
        'number_of_workers' => 2,
        'total_price' => 10000,
        'extension_fee_total' => 0,
    ]);

    createResilienceAssignment(
        booking: $booking,
        worker: $primaryWorker,
        status: CleaningBookingWorkerAssignmentStatus::Completed,
        overrides: ['work_finished_at' => now()->subMinutes(10)],
    );
    createResilienceAssignment(
        booking: $booking,
        worker: $respondingWorker,
        status: CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested,
        overrides: [
            'work_started_at' => now()->subHour(),
            'work_finished_at' => now()->subMinutes(5),
        ],
    );

    $warning = CleaningTimeWarning::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => $booking->getMorphClass(),
        'worker_id' => $respondingWorker->id,
        'customer_response' => CleaningTimeWarningResponse::ExtendTime->value,
        'customer_message' => 'Please continue for 30 minutes.',
        'worker_response' => null,
        'sent_at' => now(),
        'customer_responded_at' => now(),
        'worker_responded_at' => null,
        'additional_minutes' => 30,
        'quoted_amount' => 4500,
        'quoted_currency' => 'SYP',
        'price_applied_at' => null,
        'worker_reject_message' => null,
    ]);

    Sanctum::actingAs($respondingUser);

    Notification::fake();

    $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/accept")
        ->assertOk()
        ->assertJsonPath('data.workerId', $respondingWorker->id);
    $respondedAt = $warning->fresh()->worker_responded_at->toDateTimeString();
    $assignmentAfterFirstAcceptance = DB::table('cleaning_booking_worker_assignments')
        ->where('cleaning_booking_id', $booking->id)
        ->where('worker_id', $respondingWorker->id)
        ->first();

    $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/accept")
        ->assertOk()
        ->assertJsonPath('data.workerId', $respondingWorker->id)
        ->assertJsonPath('data.workerRespondedAt', $respondedAt);

    $booking->refresh();
    $warning->refresh();

    expect((float) $booking->extension_fee_total)->toBe(4500.0)
        ->and((float) $booking->total_price)->toBe(14500.0)
        ->and($warning->worker_responded_at)->not->toBeNull();

    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $respondingWorker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::InProgress->value,
    ]);
    expect((array) DB::table('cleaning_booking_worker_assignments')
        ->where('cleaning_booking_id', $booking->id)
        ->where('worker_id', $respondingWorker->id)
        ->first())->toBe((array) $assignmentAfterFirstAcceptance);

    expect(countWarningDecisionNotifications(
        $respondingUser,
        'cleaning.booking.time_extension_accepted',
    ))->toBe(1)->and(countWarningDecisionNotifications(
        $customer,
        'cleaning.booking.time_extension_accepted',
    ))->toBe(1);

    Event::assertDispatchedTimes(CompletionDecisionMade::class, 1);
});

it('treats repeated extension rejection as an idempotent success', function (): void {
    Event::fake([CompletionDecisionMade::class]);

    $primaryUser = User::factory()->create(['email' => 'extension-reject-primary@example.com']);
    $primaryWorker = Worker::factory()->financiallyEligible()->create(['user_id' => $primaryUser->id]);
    $respondingUser = User::factory()->create(['email' => 'extension-reject-responder@example.com']);
    $respondingWorker = Worker::factory()->financiallyEligible()->create(['user_id' => $respondingUser->id]);
    $customer = User::factory()->create(['email' => 'extension-reject-customer@example.com']);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $primaryWorker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested,
        'number_of_workers' => 2,
    ]);

    createResilienceAssignment(
        booking: $booking,
        worker: $primaryWorker,
        status: CleaningBookingWorkerAssignmentStatus::Completed,
        overrides: ['work_finished_at' => now()->subMinutes(10)],
    );
    createResilienceAssignment(
        booking: $booking,
        worker: $respondingWorker,
        status: CleaningBookingWorkerAssignmentStatus::TimeExtensionRequested,
        overrides: ['work_finished_at' => now()->subMinutes(5)],
    );

    $warning = CleaningTimeWarning::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => $booking->getMorphClass(),
        'worker_id' => $respondingWorker->id,
        'customer_response' => CleaningTimeWarningResponse::ExtendTime->value,
        'customer_message' => null,
        'worker_response' => null,
        'sent_at' => now(),
        'customer_responded_at' => now(),
        'worker_responded_at' => null,
        'additional_minutes' => 30,
        'quoted_amount' => 4500,
        'quoted_currency' => 'SYP',
        'price_applied_at' => null,
        'worker_reject_message' => null,
    ]);

    Sanctum::actingAs($respondingUser);

    Notification::fake();

    $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/reject", [
        'message' => 'Cannot continue.',
    ])->assertOk();
    $respondedAt = $warning->fresh()->worker_responded_at->toDateTimeString();
    $assignmentAfterFirstRejection = DB::table('cleaning_booking_worker_assignments')
        ->where('cleaning_booking_id', $booking->id)
        ->where('worker_id', $respondingWorker->id)
        ->first();

    $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/reject", [
        'message' => 'A changed retry message.',
    ])->assertOk()
        ->assertJsonPath('data.workerRespondedAt', $respondedAt)
        ->assertJsonPath('data.workerRejectMessage', 'Cannot continue.');

    $this->assertDatabaseHas('cleaning_booking_worker_assignments', [
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $respondingWorker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Completed->value,
    ]);
    $this->assertDatabaseHas('cleaning_bookings', [
        'id' => $booking->id,
        'status' => CleaningBookingStatus::Completed->value,
    ]);
    expect((array) DB::table('cleaning_booking_worker_assignments')
        ->where('cleaning_booking_id', $booking->id)
        ->where('worker_id', $respondingWorker->id)
        ->first())->toBe((array) $assignmentAfterFirstRejection);

    expect(countWarningDecisionNotifications(
        $respondingUser,
        'cleaning.booking.time_extension_rejected',
    ))->toBe(1)->and(countWarningDecisionNotifications(
        $customer,
        'cleaning.booking.time_extension_rejected',
    ))->toBe(1);
    Notification::assertSentTo($customer, BookingLifecycleNotification::class, function (BookingLifecycleNotification $notification): bool {
        $canonicalType = new ReflectionProperty($notification, 'canonicalType');
        $extraData = new ReflectionProperty($notification, 'extraData');

        return $canonicalType->getValue($notification) === 'cleaning.booking.time_extension_rejected'
            && ($extraData->getValue($notification)['workerRejectMessage'] ?? null) === 'Cannot continue.';
    });

    Event::assertDispatchedTimes(CompletionDecisionMade::class, 1);
});

it('rejects an opposite decision submitted from a stale warning model', function (): void {
    Event::fake([CompletionDecisionMade::class]);

    $worker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested,
        'total_price' => 12000,
        'extension_fee_total' => 0,
    ]);
    $warning = CleaningTimeWarning::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => $booking->getMorphClass(),
        'worker_id' => $worker->id,
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
        'quoted_amount' => 2400,
        'price_applied_at' => null,
    ]);
    $staleWarning = $warning->replicate();
    $staleWarning->id = $warning->id;
    $respondedAt = now()->subMinute();

    DB::table('cleaning_time_warnings')->where('id', $warning->id)->update([
        'worker_response' => CleaningTimeWarningResponse::ExtendTime->value,
        'worker_responded_at' => $respondedAt,
        'price_applied_at' => $respondedAt,
    ]);

    Notification::fake();

    $replayedWarning = app(CleaningTimeWarningService::class)->accept($staleWarning, 99);

    expect($replayedWarning->worker_response)->toBe(CleaningTimeWarningResponse::ExtendTime)
        ->and($replayedWarning->worker_responded_at->toIso8601String())->toBe($respondedAt->toIso8601String())
        ->and((float) $booking->fresh()->extension_fee_total)->toBe(0.0)
        ->and((float) $booking->fresh()->total_price)->toBe(12000.0);

    expect(fn () => app(CleaningTimeWarningService::class)->reject($staleWarning, 'Changed decision'))
        ->toThrow(InvalidArgumentException::class);
    expect($warning->fresh()->worker_responded_at->toIso8601String())
        ->toBe($respondedAt->toIso8601String())
        ->and($warning->fresh()->worker_response)->toBe(CleaningTimeWarningResponse::ExtendTime)
        ->and($warning->fresh()->worker_reject_message)->toBeNull()
        ->and((float) $booking->fresh()->extension_fee_total)->toBe(0.0)
        ->and((float) $booking->fresh()->total_price)->toBe(12000.0);

    Event::assertNothingDispatched();
    Notification::assertNothingSent();
});

it('silently ignores stale multi-worker location updates outside the travel window', function (): void {
    Event::fake([WorkerLocationUpdated::class]);

    $primaryUser = User::factory()->create(['email' => 'location-primary@example.com']);
    $primaryWorker = Worker::factory()->financiallyEligible()->create(['user_id' => $primaryUser->id]);
    $travellingUser = User::factory()->create(['email' => 'location-team-worker@example.com']);
    $travellingWorker = Worker::factory()->financiallyEligible()->create(['user_id' => $travellingUser->id]);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $primaryWorker->id,
        'billing_policy_id' => $this->billingPolicy->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'number_of_workers' => 2,
    ]);

    createResilienceAssignment(
        booking: $booking,
        worker: $primaryWorker,
        status: CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
        overrides: ['started_travel_at' => now()->subMinutes(5)],
    );
    createResilienceAssignment(
        booking: $booking,
        worker: $travellingWorker,
        status: CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart,
    );

    Sanctum::actingAs($travellingUser);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/location", [
        'latitude' => 33.5138,
        'longitude' => 36.2765,
    ])->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.ignored', true);

    DB::table('cleaning_booking_worker_assignments')
        ->where('cleaning_booking_id', $booking->id)
        ->where('worker_id', $travellingWorker->id)
        ->update([
            'started_travel_at' => now()->subMinute(),
            'arrived_at' => null,
            'updated_at' => now(),
        ]);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/location", [
        'latitude' => 33.5140,
        'longitude' => 36.2770,
    ])->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.ignored', false);

    DB::table('cleaning_booking_worker_assignments')
        ->where('cleaning_booking_id', $booking->id)
        ->where('worker_id', $travellingWorker->id)
        ->update([
            'arrived_at' => now(),
            'updated_at' => now(),
        ]);

    $this->postJson("/api/v1/cleaning-bookings/{$booking->id}/location", [
        'latitude' => 33.5142,
        'longitude' => 36.2772,
    ])->assertOk()
        ->assertJsonPath('data.ok', true)
        ->assertJsonPath('data.ignored', true);

    Event::assertDispatchedTimes(WorkerLocationUpdated::class, 1);
    Event::assertDispatched(WorkerLocationUpdated::class, function (WorkerLocationUpdated $event) use ($booking, $travellingWorker): bool {
        return $event->cleaningBookingId === $booking->id
            && $event->workerId === $travellingWorker->id;
    });
});

function createResilienceAssignment(
    CleaningBooking $booking,
    Worker $worker,
    CleaningBookingWorkerAssignmentStatus $status,
    array $overrides = [],
): void {
    DB::table('cleaning_booking_worker_assignments')->insert(array_merge([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => $status->value,
        'accepted_at' => now()->subHour(),
        'started_travel_at' => null,
        'arrived_at' => null,
        'start_approved_at' => null,
        'work_started_at' => null,
        'work_finished_at' => null,
        'worker_completion_message' => null,
        'worker_finished_cleaning_services' => null,
        'worker_finished_property_rooms' => null,
        'room_count' => 0,
        'rooms_weight' => 0,
        'service_share_amount' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'worker_amount' => 0,
        'currency' => (string) config('app.currency', 'SYP'),
        'created_at' => now(),
        'updated_at' => now(),
    ], $overrides));
}

function countWarningDecisionNotifications(User $recipient, string $canonicalType): int
{
    return Notification::sent(
        $recipient,
        BookingLifecycleNotification::class,
        static function (BookingLifecycleNotification $notification) use ($canonicalType): bool {
            $canonicalTypeProperty = new ReflectionProperty($notification, 'canonicalType');

            return $canonicalTypeProperty->getValue($notification) === $canonicalType;
        },
    )->count();
}
