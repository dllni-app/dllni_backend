<?php

declare(strict_types=1);

use App\Models\Dispute;
use App\Models\DisputeMessage;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

it('allows worker to post a dispute message for assigned cleaning booking dispute', function () {
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::Completed->value,
    ]);

    $dispute = Dispute::create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'ticket_number' => 'DSP-WRK-0001',
        'category' => 'poor_quality',
        'status' => 'open',
    ]);

    $response = postJson("/api/v1/disputes/{$dispute->id}/messages", [
        'message' => 'تمت مراجعة الشكوى وسأتواصل مع العميل.',
    ]);

    $response->assertCreated();
    expect($response->json('data.id'))->toBe($dispute->id);
    expect($response->json('data.messages'))->toBeArray();

    expect(DB::table('dispute_messages')
        ->where('dispute_id', $dispute->id)
        ->where('sender_id', $workerUser->id)
        ->where('sender_type', 'worker')
        ->where('body', 'تمت مراجعة الشكوى وسأتواصل مع العميل.')
        ->exists())->toBeTrue();
});

it('forbids worker from posting message to unassigned dispute', function () {
    $workerUser = User::factory()->create();
    Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $otherWorker = Worker::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'worker_id' => $otherWorker->id,
        'status' => CleaningBookingStatus::Completed->value,
    ]);

    $dispute = Dispute::create([
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'ticket_number' => 'DSP-WRK-0002',
        'category' => 'poor_quality',
        'status' => 'open',
    ]);

    $response = postJson("/api/v1/disputes/{$dispute->id}/messages", [
        'message' => 'محاولة رد غير مسموح.',
    ]);

    $response->assertForbidden();
    expect(DisputeMessage::query()->where('dispute_id', $dispute->id)->count())->toBe(0);
});

it('filters disputes for the current worker when requested', function () {
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $assignedBooking = CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::Completed->value,
    ]);

    $unassignedBooking = CleaningBooking::factory()->create([
        'worker_id' => Worker::factory()->create()->id,
        'status' => CleaningBookingStatus::Completed->value,
    ]);

    $assignedDispute = Dispute::create([
        'booking_id' => $assignedBooking->id,
        'booking_type' => 'cleaning_booking',
        'ticket_number' => 'DSP-WRK-0003',
        'category' => 'poor_quality',
        'status' => 'open',
    ]);

    Dispute::create([
        'booking_id' => $unassignedBooking->id,
        'booking_type' => 'cleaning_booking',
        'ticket_number' => 'DSP-WRK-0004',
        'category' => 'poor_quality',
        'status' => 'open',
    ]);

    $response = getJson('/api/v1/disputes?filter[forCurrentWorker]=1');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(1);
    expect($response->json('data.0.id'))->toBe($assignedDispute->id);
});
