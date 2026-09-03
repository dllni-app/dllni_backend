<?php

declare(strict_types=1);

use App\Enums\WorkerCustomerRatingType;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerCustomerRating;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\User\Services\EventAssistanceReviewService;

use function Pest\Laravel\postJson;

it('rejects an event review before the whole parent event is completed', function (): void {
    [$booking] = makeReviewEvent(CleaningBookingStatus::InProgress->value, 1);

    expect(fn () => app(EventAssistanceReviewService::class)->submit($booking, [
        'rating' => 5,
        'comment' => 'ممتاز',
    ]))->toThrow(\Illuminate\Validation\ValidationException::class);

    expect(WorkerCustomerRating::query()->where('booking_id', $booking->id)->count())->toBe(0);
});

it('applies one completed event review to every distinct completed team worker', function (): void {
    [$booking, $workers] = makeReviewEvent(CleaningBookingStatus::Completed->value, 2);

    $reviews = app(EventAssistanceReviewService::class)->submit($booking, [
        'rating' => 4,
        'comment' => 'خدمة مناسبة جيدة',
    ]);

    expect($reviews)->toHaveCount(2)
        ->and(WorkerCustomerRating::query()
            ->where('booking_id', $booking->id)
            ->where('customer_id', $booking->customer_id)
            ->where('rating_type', WorkerCustomerRatingType::CustomerToWorker->value)
            ->count())->toBe(2);

    foreach ($workers as $worker) {
        expect(WorkerCustomerRating::query()
            ->where('booking_id', $booking->id)
            ->where('worker_id', $worker->id)
            ->where('rating', 4)
            ->exists())->toBeTrue();
    }
});

it('rejects a second parent-level event review', function (): void {
    [$booking] = makeReviewEvent(CleaningBookingStatus::Completed->value, 2);
    $service = app(EventAssistanceReviewService::class);

    $service->submit($booking, ['rating' => 5]);

    expect(fn () => $service->submit($booking->fresh(), ['rating' => 3]))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});

it('keeps workerId required for ordinary cleaning reviews', function (): void {
    $customer = User::factory()->create();
    Sanctum::actingAs($customer);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'property_type' => 'apartment',
        'status' => CleaningBookingStatus::Completed->value,
    ]);

    postJson("/api/v1/user/cleaning/orders/{$booking->id}/review", [
        'rating' => 5,
    ])->assertUnprocessable()->assertJsonValidationErrors(['workerId']);
});

it('exposes event canReview and hasReview in the customer schedule envelope', function (): void {
    [$booking] = makeReviewEvent(CleaningBookingStatus::Completed->value, 1);
    $customer = User::query()->findOrFail($booking->customer_id);
    Sanctum::actingAs($customer);

    $before = \Pest\Laravel\getJson("/api/v1/cleaning/bookings/{$booking->id}/schedule");
    $before->assertOk();
    expect($before->json('data.canReview'))->toBeTrue()
        ->and($before->json('data.hasReview'))->toBeFalse();

    postJson("/api/v1/user/cleaning/orders/{$booking->id}/review", [
        'rating' => 5,
        'comment' => 'ممتاز',
    ])->assertOk();

    $after = \Pest\Laravel\getJson("/api/v1/cleaning/bookings/{$booking->id}/schedule");
    $after->assertOk();
    expect($after->json('data.canReview'))->toBeFalse()
        ->and($after->json('data.hasReview'))->toBeTrue();
});

/**
 * @return array{0:CleaningBooking,1:array<int,Worker>}
 */
function makeReviewEvent(string $status, int $workerCount): array
{
    $customer = User::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'property_type' => 'event_assistance',
        'status' => $status,
        'number_of_workers' => $workerCount,
        'scheduled_date' => now()->subDays(2)->toDateString(),
        'scheduled_time' => '10:00',
    ]);

    $workers = [];
    for ($index = 0; $index < $workerCount; $index++) {
        $workerUser = User::factory()->create();
        $workers[] = Worker::factory()->create([
            'user_id' => $workerUser->id,
            'is_active' => true,
        ]);
    }

    foreach ([1, 2] as $sequence) {
        $session = CleaningBookingSession::query()->create([
            'cleaning_booking_id' => $booking->id,
            'sequence' => $sequence,
            'session_type' => 'event_day',
            'calculation_mode' => 'hours',
            'scheduled_date' => now()->subDays(3 - $sequence)->toDateString(),
            'scheduled_time' => '10:00',
            'duration_hours' => 2,
            'required_workers' => $workerCount,
            'coverage_status' => 'fully_covered',
            'status' => $status === CleaningBookingStatus::Completed->value ? 'completed' : 'in_progress',
            'base_price' => 1000,
            'total_price' => 1000,
            'is_pricing_final' => true,
            'work_started_at' => now()->subDay(),
            'work_finished_at' => $status === CleaningBookingStatus::Completed->value ? now()->subHours(2) : null,
            'customer_completed_at' => $status === CleaningBookingStatus::Completed->value ? now()->subHours(2) : null,
        ]);

        foreach ($workers as $worker) {
            CleaningBookingSessionWorkerAssignment::query()->create([
                'cleaning_booking_session_id' => $session->id,
                'worker_id' => $worker->id,
                'status' => $status === CleaningBookingStatus::Completed->value ? 'completed' : 'in_progress',
                'accepted_at' => now()->subDays(4),
                'work_started_at' => now()->subDay(),
                'work_finished_at' => $status === CleaningBookingStatus::Completed->value ? now()->subHours(2) : null,
                'service_share_amount' => 500,
                'travel_fee' => 0,
                'admin_margin_amount' => 50,
                'worker_amount' => 450,
                'currency' => 'SYP',
            ]);
        }
    }

    return [$booking->fresh(), $workers];
}
