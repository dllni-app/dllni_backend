<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Enums\EventBookingStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Events\CompletionDecisionMade;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;
use Modules\Cleaning\Models\EventBooking;
use Modules\Cleaning\Models\CleaningTimeWarning;
use Modules\Cleaning\Services\CleaningTimeWarningService;

function terminalStateWarning(CleaningBooking $booking, ?string $customerResponse): CleaningTimeWarning
{
    return CleaningTimeWarning::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => $booking->getMorphClass(),
        'customer_response' => $customerResponse,
        'customer_responded_at' => $customerResponse === null ? null : now(),
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
        'additional_minutes' => 30,
        'quoted_base_amount' => 50,
        'quoted_admin_margin_amount' => 5,
        'quoted_amount' => 55,
        'quoted_currency' => 'SYP',
        'price_applied_at' => null,
    ]);
}

it('does not accept an extension for a cancelled booking', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Cancelled,
        'cancelled_at' => now(),
        'total_price' => 1000,
        'extension_fee_total' => 0,
    ]);
    $warning = terminalStateWarning($booking, 'extend_time');

    expect(fn () => app(CleaningTimeWarningService::class)->accept($warning))
        ->toThrow(InvalidArgumentException::class);

    expect($booking->fresh()->status)->toBe(CleaningBookingStatus::Cancelled)
        ->and((float) $booking->fresh()->total_price)->toBe(1000.0)
        ->and((float) $booking->fresh()->extension_fee_total)->toBe(0.0)
        ->and($warning->fresh()->worker_responded_at)->toBeNull()
        ->and($warning->fresh()->price_applied_at)->toBeNull();
});

it('does not reject an extension for a cancelled booking', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Cancelled,
        'cancelled_at' => now(),
        'total_price' => 1000,
        'extension_fee_total' => 0,
    ]);
    $warning = terminalStateWarning($booking, 'extend_time');

    expect(fn () => app(CleaningTimeWarningService::class)->reject($warning))
        ->toThrow(InvalidArgumentException::class);

    expect($booking->fresh()->status)->toBe(CleaningBookingStatus::Cancelled)
        ->and((float) $booking->fresh()->total_price)->toBe(1000.0)
        ->and((float) $booking->fresh()->extension_fee_total)->toBe(0.0)
        ->and($warning->fresh()->worker_responded_at)->toBeNull();
});

it('does not accept an extension before customer consent', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::InProgress,
        'total_price' => 1000,
        'extension_fee_total' => 0,
    ]);
    $warning = terminalStateWarning($booking, null);

    expect(fn () => app(CleaningTimeWarningService::class)->accept($warning))
        ->toThrow(InvalidArgumentException::class);

    expect($booking->fresh()->status)->toBe(CleaningBookingStatus::InProgress)
        ->and((float) $booking->fresh()->total_price)->toBe(1000.0)
        ->and((float) $booking->fresh()->extension_fee_total)->toBe(0.0)
        ->and($warning->fresh()->worker_responded_at)->toBeNull()
        ->and($warning->fresh()->price_applied_at)->toBeNull();
});

it('does not reject an extension before customer consent', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::InProgress,
        'total_price' => 1000,
        'extension_fee_total' => 0,
    ]);
    $warning = terminalStateWarning($booking, null);

    expect(fn () => app(CleaningTimeWarningService::class)->reject($warning))
        ->toThrow(InvalidArgumentException::class);

    expect($booking->fresh()->status)->toBe(CleaningBookingStatus::InProgress)
        ->and((float) $booking->fresh()->total_price)->toBe(1000.0)
        ->and((float) $booking->fresh()->extension_fee_total)->toBe(0.0)
        ->and($warning->fresh()->worker_responded_at)->toBeNull();
});

it('denies an unauthorized warning decision over HTTP without changing booking money or emitting decisions',
    function (string $status, ?string $customerResponse, string $decision): void {
        Event::fake([CleaningBookingTrackingUpdated::class, CompletionDecisionMade::class]);
        $user = User::factory()->create();
        $worker = Worker::factory()->create(['user_id' => $user->id]);
        Sanctum::actingAs($user);

        $booking = CleaningBooking::factory()->create([
            'worker_id' => $worker->id,
            'status' => $status,
            'cancelled_at' => $status === CleaningBookingStatus::Cancelled->value ? now() : null,
            'total_price' => 1000,
            'extension_fee_total' => 0,
        ]);
        $warning = terminalStateWarning($booking, $customerResponse);

        $this->postJson("/api/v1/cleaning-time-warnings/{$warning->id}/{$decision}")
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['warning']);

        expect((string) $booking->fresh()->status->value)->toBe($status)
            ->and((float) $booking->fresh()->total_price)->toBe(1000.0)
            ->and((float) $booking->fresh()->extension_fee_total)->toBe(0.0)
            ->and($warning->fresh()->worker_responded_at)->toBeNull()
            ->and($warning->fresh()->price_applied_at)->toBeNull();
        Event::assertNotDispatched(CleaningBookingTrackingUpdated::class);
        Event::assertNotDispatched(CompletionDecisionMade::class);
    },
)->with([
    'cancelled accept' => [CleaningBookingStatus::Cancelled->value, 'extend_time', 'accept'],
    'cancelled reject' => [CleaningBookingStatus::Cancelled->value, 'extend_time', 'reject'],
    'unapproved accept' => [CleaningBookingStatus::InProgress->value, null, 'accept'],
    'unapproved reject' => [CleaningBookingStatus::InProgress->value, null, 'reject'],
    'customer declined' => [CleaningBookingStatus::TimeExtensionRequested->value, 'commit_current_time', 'accept'],
]);

it('does not allow a replaced or withdrawn worker to decide an old extension', function (string $decision): void {
    Event::fake([CleaningBookingTrackingUpdated::class, CompletionDecisionMade::class]);
    $formerWorker = Worker::factory()->create();
    $currentWorker = Worker::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'worker_id' => $currentWorker->id,
        'status' => CleaningBookingStatus::TimeExtensionRequested,
        'number_of_workers' => 2,
        'total_price' => 1000,
        'extension_fee_total' => 0,
    ]);
    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $formerWorker->id,
        'status' => CleaningBookingWorkerAssignmentStatus::Withdrawn,
        'service_share_amount' => 200,
        'worker_amount' => 200,
        'currency' => 'SYP',
    ]);
    $warning = terminalStateWarning($booking, 'extend_time');
    $warning->forceFill(['worker_id' => $formerWorker->id])->save();

    expect(fn () => app(CleaningTimeWarningService::class)->{$decision}($warning))
        ->toThrow(InvalidArgumentException::class);
    expect($booking->fresh()->status)->toBe(CleaningBookingStatus::TimeExtensionRequested)
        ->and((float) $booking->fresh()->total_price)->toBe(1000.0)
        ->and((float) $booking->fresh()->extension_fee_total)->toBe(0.0)
        ->and($warning->fresh()->worker_response)->toBeNull()
        ->and($warning->fresh()->price_applied_at)->toBeNull();
    Event::assertNotDispatched(CleaningBookingTrackingUpdated::class);
    Event::assertNotDispatched(CompletionDecisionMade::class);
})->with(['accept', 'reject']);

it('refuses a legacy event extension rejection outside active work', function (string $status): void {
    Event::fake([CleaningBookingTrackingUpdated::class, CompletionDecisionMade::class]);
    $booking = EventBooking::factory()->create(['status' => $status]);
    $warning = CleaningTimeWarning::query()->create([
        'booking_id' => $booking->id,
        'booking_type' => $booking->getMorphClass(),
        'worker_response' => null,
        'worker_responded_at' => null,
        'sent_at' => now(),
    ]);

    expect(fn () => app(CleaningTimeWarningService::class)->reject($warning))
        ->toThrow(InvalidArgumentException::class);
    expect($booking->fresh()->status->value)->toBe($status)
        ->and($warning->fresh()->worker_response)->toBeNull()
        ->and($warning->fresh()->worker_responded_at)->toBeNull();
    Event::assertNotDispatched(CleaningBookingTrackingUpdated::class);
    Event::assertNotDispatched(CompletionDecisionMade::class);
})->with([
    EventBookingStatus::Pending->value,
    EventBookingStatus::Confirmed->value,
    EventBookingStatus::TeamAssigned->value,
    EventBookingStatus::Completed->value,
    EventBookingStatus::Cancelled->value,
]);
