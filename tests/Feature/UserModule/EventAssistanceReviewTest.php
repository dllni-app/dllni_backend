<?php

declare(strict_types=1);

use App\Enums\WorkerCustomerRatingType;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerCustomerRating;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\User\Services\EventAssistanceReviewService;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

it('rejects an event review before the whole parent event is completed', function (): void {
    [$booking, $workers] = makeReviewEvent(CleaningBookingStatus::InProgress->value, 1);

    expect(fn () => app(EventAssistanceReviewService::class)->submit($booking, [
        'reviews' => [[
            'workerId' => $workers[0]->id,
            'rating' => 5,
            'comment' => 'ممتاز',
        ]],
    ]))->toThrow(ValidationException::class);

    expect(WorkerCustomerRating::query()->where('booking_id', $booking->id)->count())->toBe(0);
});

it('stores one event review when the same worker participates in every session', function (): void {
    [$booking, $workers] = makeReviewEvent(CleaningBookingStatus::Completed->value, 1);

    $reviews = app(EventAssistanceReviewService::class)->submit($booking, [
        'reviews' => [[
            'workerId' => $workers[0]->id,
            'rating' => 5,
            'comment' => 'عامل ممتاز',
        ]],
    ]);

    expect($reviews)->toHaveCount(1)
        ->and(WorkerCustomerRating::query()
            ->where('booking_id', $booking->id)
            ->where('worker_id', $workers[0]->id)
            ->where('rating', 5)
            ->where('comment', 'عامل ممتاز')
            ->count())->toBe(1);
});

it('stores an independent rating for each distinct participating event worker', function (): void {
    [$booking, $workers] = makeReviewEvent(
        CleaningBookingStatus::Completed->value,
        3,
        [[0], [1], [2]],
    );

    $reviews = app(EventAssistanceReviewService::class)->submit($booking, [
        'reviews' => [
            ['workerId' => $workers[0]->id, 'rating' => 5, 'comment' => 'الأول ممتاز'],
            ['workerId' => $workers[1]->id, 'rating' => 4, 'comment' => 'الثاني جيد'],
            ['workerId' => $workers[2]->id, 'rating' => 3],
        ],
    ]);

    expect($reviews)->toHaveCount(3)
        ->and(WorkerCustomerRating::query()
            ->where('booking_id', $booking->id)
            ->where('customer_id', $booking->customer_id)
            ->where('rating_type', WorkerCustomerRatingType::CustomerToWorker->value)
            ->count())->toBe(3);

    expect(WorkerCustomerRating::query()
        ->where('booking_id', $booking->id)
        ->where('worker_id', $workers[0]->id)
        ->where('rating', 5)
        ->where('comment', 'الأول ممتاز')
        ->exists())->toBeTrue();
    expect(WorkerCustomerRating::query()
        ->where('booking_id', $booking->id)
        ->where('worker_id', $workers[1]->id)
        ->where('rating', 4)
        ->where('comment', 'الثاني جيد')
        ->exists())->toBeTrue();
    expect(WorkerCustomerRating::query()
        ->where('booking_id', $booking->id)
        ->where('worker_id', $workers[2]->id)
        ->where('rating', 3)
        ->exists())->toBeTrue();
});

it('deduplicates repeated participants across mixed event sessions', function (): void {
    [$booking, $workers] = makeReviewEvent(
        CleaningBookingStatus::Completed->value,
        3,
        [[0, 1], [1, 2], [0, 2]],
    );

    app(EventAssistanceReviewService::class)->submit($booking, [
        'reviews' => [
            ['workerId' => $workers[0]->id, 'rating' => 5],
            ['workerId' => $workers[1]->id, 'rating' => 4],
            ['workerId' => $workers[2]->id, 'rating' => 3],
        ],
    ]);

    expect(WorkerCustomerRating::query()
        ->where('booking_id', $booking->id)
        ->where('rating_type', WorkerCustomerRatingType::CustomerToWorker->value)
        ->count())->toBe(3);
});

it('rejects duplicate or incomplete event worker review payloads', function (): void {
    [$booking, $workers] = makeReviewEvent(CleaningBookingStatus::Completed->value, 2);
    $service = app(EventAssistanceReviewService::class);

    expect(fn () => $service->submit($booking, [
        'reviews' => [
            ['workerId' => $workers[0]->id, 'rating' => 5],
            ['workerId' => $workers[0]->id, 'rating' => 4],
        ],
    ]))->toThrow(ValidationException::class);

    expect(fn () => $service->submit($booking->fresh(), [
        'reviews' => [
            ['workerId' => $workers[0]->id, 'rating' => 5],
        ],
    ]))->toThrow(ValidationException::class);

    expect(WorkerCustomerRating::query()->where('booking_id', $booking->id)->count())->toBe(0);
});

it('rejects a second event review for the same participating workers', function (): void {
    [$booking, $workers] = makeReviewEvent(CleaningBookingStatus::Completed->value, 2);
    $service = app(EventAssistanceReviewService::class);
    $payload = [
        'reviews' => [
            ['workerId' => $workers[0]->id, 'rating' => 5],
            ['workerId' => $workers[1]->id, 'rating' => 4],
        ],
    ];

    $service->submit($booking, $payload);

    expect(fn () => $service->submit($booking->fresh(), $payload))
        ->toThrow(ValidationException::class);
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

it('exposes event canReview and hasReview around the per-worker review submission', function (): void {
    [$booking, $workers] = makeReviewEvent(CleaningBookingStatus::Completed->value, 2);
    $customer = User::query()->findOrFail($booking->customer_id);
    Sanctum::actingAs($customer);

    $before = getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule");
    $before->assertOk();
    expect($before->json('data.canReview'))->toBeTrue()
        ->and($before->json('data.hasReview'))->toBeFalse();

    postJson("/api/v1/user/cleaning/orders/{$booking->id}/review", [
        'reviews' => [
            ['workerId' => $workers[0]->id, 'rating' => 5, 'comment' => 'ممتاز'],
            ['workerId' => $workers[1]->id, 'rating' => 3, 'comment' => 'جيد'],
        ],
    ])->assertOk();

    $after = getJson("/api/v1/cleaning-bookings/{$booking->id}/schedule");
    $after->assertOk();
    expect($after->json('data.canReview'))->toBeFalse()
        ->and($after->json('data.hasReview'))->toBeTrue();
});

/**
 * @param  array<int,array<int,int>>|null  $sessionWorkerIndexes
 * @return array{0:CleaningBooking,1:array<int,Worker>}
 */
function makeReviewEvent(string $status, int $workerCount, ?array $sessionWorkerIndexes = null): array
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

    $sessionWorkerIndexes ??= [
        range(0, $workerCount - 1),
        range(0, $workerCount - 1),
    ];

    foreach ($sessionWorkerIndexes as $sessionIndex => $workerIndexes) {
        $sequence = $sessionIndex + 1;
        $session = CleaningBookingSession::query()->create([
            'cleaning_booking_id' => $booking->id,
            'sequence' => $sequence,
            'session_type' => 'event_day',
            'calculation_mode' => 'hours',
            'scheduled_date' => now()->subDays(count($sessionWorkerIndexes) - $sessionIndex)->toDateString(),
            'scheduled_time' => '10:00',
            'duration_hours' => 2,
            'required_workers' => count($workerIndexes),
            'coverage_status' => 'fully_covered',
            'status' => $status === CleaningBookingStatus::Completed->value ? 'completed' : 'in_progress',
            'base_price' => 1000,
            'total_price' => 1000,
            'is_pricing_final' => true,
            'work_started_at' => now()->subDay(),
            'work_finished_at' => $status === CleaningBookingStatus::Completed->value ? now()->subHours(2) : null,
            'customer_completed_at' => $status === CleaningBookingStatus::Completed->value ? now()->subHours(2) : null,
        ]);

        foreach ($workerIndexes as $workerIndex) {
            $worker = $workers[$workerIndex];
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
