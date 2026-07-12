<?php

declare(strict_types=1);

use App\Enums\WorkerCustomerRatingType;
use App\Models\User;
use App\Models\Worker;
use App\Models\WorkerCustomerRating;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

it('returns worker reviews with aggregate meta', function (): void {
    $workerUser = User::factory()->create(['email' => 'worker-reviews@example.com']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $customerOne = User::factory()->create(['name' => 'Mohammad Al Tayeb']);
    $customerTwo = User::factory()->create(['name' => 'Sara Al Harbi']);

    $bookingOne = CleaningBooking::factory()->create([
        'customer_id' => $customerOne->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::Completed,
    ]);
    $bookingTwo = CleaningBooking::factory()->create([
        'customer_id' => $customerTwo->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::Completed,
    ]);
    CleaningBooking::factory()->create([
        'worker_id' => Worker::factory()->create()->id,
        'status' => CleaningBookingStatus::Completed,
    ]);

    WorkerCustomerRating::query()->create([
        'booking_id' => $bookingOne->id,
        'booking_type' => 'cleaning_booking',
        'worker_id' => $worker->id,
        'customer_id' => $customerOne->id,
        'rating_type' => WorkerCustomerRatingType::CustomerToWorker,
        'rating' => 5,
        'comment' => 'Excellent service.',
    ]);
    DB::table('worker_customer_ratings')->where('booking_id', $bookingOne->id)->update([
        'created_at' => now()->subDay(),
        'updated_at' => now()->subDay(),
    ]);

    WorkerCustomerRating::query()->create([
        'booking_id' => $bookingTwo->id,
        'booking_type' => 'cleaning_booking',
        'worker_id' => $worker->id,
        'customer_id' => $customerTwo->id,
        'rating_type' => WorkerCustomerRatingType::CustomerToWorker,
        'rating' => 4,
        'comment' => 'Good work.',
    ]);
    DB::table('worker_customer_ratings')->where('booking_id', $bookingTwo->id)->update([
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $response = $this->getJson('/api/v1/cleaning/worker/reviews?page=1&perPage=20');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(2);
    expect($response->json('data.0.customerName'))->toBe('Sara Al Harbi');
    expect($response->json('data.0.rating'))->toBe(4);
    expect($response->json('data.0.comment'))->toBe('Good work.');
    expect($response->json('data.1.customerName'))->toBe('Mohammad Al Tayeb');
    expect($response->json('meta.averageRating'))->toBe(4.5);
    expect($response->json('meta.totalCount'))->toBe(2);
    expect($response->json('meta.currentPage'))->toBe(1);
    expect($response->json('meta.lastPage'))->toBe(1);
    expect($response->json('meta.perPage'))->toBe(20);
});

it('returns an empty reviews collection for a worker with no reviews', function (): void {
    $workerUser = User::factory()->create(['email' => 'worker-empty-reviews@example.com']);
    Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $response = $this->getJson('/api/v1/cleaning/worker/reviews');

    $response->assertOk();
    expect($response->json('data'))->toBeArray()->toBe([]);
    expect($response->json('meta.averageRating'))->toBe(0);
    expect($response->json('meta.totalCount'))->toBe(0);
    expect($response->json('meta.currentPage'))->toBe(1);
    expect($response->json('meta.lastPage'))->toBe(1);
    expect($response->json('meta.perPage'))->toBe(20);
});

it('rejects invalid pagination parameters', function (): void {
    $workerUser = User::factory()->create(['email' => 'worker-invalid-pagination@example.com']);
    Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $response = $this->getJson('/api/v1/cleaning/worker/reviews?page=0&perPage=200');

    $response->assertUnprocessable();
});

it('returns 403 when the authenticated user has no worker profile', function (): void {
    $user = User::factory()->create(['email' => 'no-worker-profile@example.com']);
    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/cleaning/worker/reviews');

    $response->assertForbidden();
    expect($response->json('message'))->toBe('User must have an associated worker.');
});
