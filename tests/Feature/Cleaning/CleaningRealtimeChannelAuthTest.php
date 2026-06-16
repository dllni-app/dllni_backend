<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use App\Providers\AppServiceProvider;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingWorkerAssignment;

beforeEach(function (): void {
    config()->set('broadcasting.default', 'pusher');
    config()->set('broadcasting.connections.pusher', [
        'driver' => 'pusher',
        'key' => 'test-pusher-key',
        'secret' => 'test-pusher-secret',
        'app_id' => 'test-pusher-app-id',
        'options' => [
            'cluster' => 'eu',
            'useTLS' => true,
        ],
    ]);

    (new AppServiceProvider(app()))->boot();
});

it('authorizes the assigned worker for private cleaning worker channel', function (): void {
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $token = $workerUser->createToken('user-api')->plainTextToken;

    $response = $this
        ->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => "Bearer {$token}",
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->post('/broadcasting/auth', [
            'socket_id' => '267922.520045',
            'channel_name' => "private-cleaning-worker.{$worker->id}",
        ]);

    $response->assertOk()
        ->assertJsonStructure(['auth']);
});

it('forbids subscribing to another worker private channel', function (): void {
    $workerUser = User::factory()->create();
    Worker::factory()->create(['user_id' => $workerUser->id]);
    $otherWorker = Worker::factory()->create();
    $token = $workerUser->createToken('user-api')->plainTextToken;

    $response = $this
        ->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => "Bearer {$token}",
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->post('/broadcasting/auth', [
            'socket_id' => '267922.520045',
            'channel_name' => "private-cleaning-worker.{$otherWorker->id}",
        ]);

    $response->assertForbidden();
});

it('authorizes committed team workers for private cleaning booking channel', function (CleaningBookingWorkerAssignmentStatus $assignmentStatus): void {
    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => null,
        'status' => CleaningBookingStatus::AwaitingWorkerStartConfirmation,
    ]);
    CleaningBookingWorkerAssignment::query()->create([
        'cleaning_booking_id' => $booking->id,
        'worker_id' => $worker->id,
        'status' => $assignmentStatus,
        'accepted_at' => now(),
    ]);
    $token = $workerUser->createToken('user-api')->plainTextToken;

    $response = $this
        ->withHeaders([
            'Accept' => 'application/json',
            'Authorization' => "Bearer {$token}",
            'X-Requested-With' => 'XMLHttpRequest',
        ])
        ->post('/broadcasting/auth', [
            'socket_id' => '267922.520045',
            'channel_name' => "private-cleaning-booking.{$booking->id}",
        ]);

    $response->assertOk()
        ->assertJsonStructure(['auth']);
})->with([
    'accepted' => [CleaningBookingWorkerAssignmentStatus::Accepted],
    'awaiting start verification' => [CleaningBookingWorkerAssignmentStatus::AwaitingStartVerification],
    'start approved' => [CleaningBookingWorkerAssignmentStatus::StartApproved],
]);
