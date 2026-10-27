<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use App\Models\CleaningFinancialSetting;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;

it('returns worker avatar and Arabic cleaning labels in booking payloads', function (): void {
    $disk = (string) (config('media-library.disk_name') ?: config('filesystems.default', 'public'));
    Storage::fake($disk);

    $viewer = User::factory()->create();
    $workerUser = User::factory()->create(['name' => 'Worker User']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $worker->addMedia(UploadedFile::fake()->image('worker-avatar.jpg', 64, 64))
        ->toMediaCollection('avatar');

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $viewer->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'property_details' => [
            'address' => 'Aleppo - Al Furqan',
            'location_name' => 'Home',
            'cleaning_mode' => 'deep',
            'living_room_size' => 'large',
        ],
    ]);

    CleaningBookingRoom::query()->create([
        'cleaning_booking_id' => $booking->id,
        'room_key' => 'bedroom.large.1',
        'room_type' => 'bedroom',
        'room_size' => 'large',
        'display_label' => 'Bedroom 1 - Large',
        'weight' => 1,
    ]);

    Sanctum::actingAs($viewer);

    // An unrelated authenticated account cannot inspect another customer's
    // booking or the assigned worker's profile and room details.
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertForbidden();

    Sanctum::actingAs($workerUser);

    $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");

    $response->assertOk()
        ->assertJsonPath('data.worker.avatarUrl', $worker->getFirstMediaUrl('avatar'))
        ->assertJsonPath('data.propertyDetails.cleaning_mode_label', 'تنظيف عميق')
        ->assertJsonPath('data.propertyDetails.living_room_size_label', 'كبيرة')
        ->assertJsonPath('data.roomTasks.0.roomTypeLabel', 'غرفة نوم')
        ->assertJsonPath('data.roomTasks.0.roomSizeLabel', 'كبيرة')
        ->assertJsonPath('data.openTime', null);
});

it('exposes the active visit supervisor only to a worker who accepted that visit on a standard group booking', function (): void {
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $otherWorkerUser = User::factory()->create();
    $otherWorker = Worker::factory()->create(['user_id' => $otherWorkerUser->id]);
    $supervisor = User::factory()->create(['name' => 'Visit Supervisor', 'phone' => '0991234567']);
    $formerSupervisor = User::factory()->create(['name' => 'Former Supervisor']);
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => CleaningBookingStatus::WorkerAssigned,
    ]);
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'calculation_mode' => 'task',
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'coverage_status' => 'fully_covered',
        'status' => 'worker_assigned',
    ]);
    $session->workerAssignments()->create(['worker_id' => $worker->id, 'status' => 'accepted', 'accepted_at' => now()]);
    $session->workerAssignments()->create(['worker_id' => $otherWorker->id, 'status' => 'pending']);
    $session->supervisorAssignments()->create([
        'supervisor_user_id' => $formerSupervisor->id,
        'assigned_by_user_id' => $formerSupervisor->id,
        'assigned_at' => now()->subHour(),
        'released_at' => now(),
        'active_slot' => null,
        'compensation_hourly_rate' => 0,
    ]);
    $session->supervisorAssignments()->create([
        'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $supervisor->id,
        'assigned_at' => now(),
        'active_slot' => 1,
        'compensation_hourly_rate' => 0,
    ]);

    expect($booking->supervisorAssignments()->count())->toBe(0);
    $acceptedRequest = \Illuminate\Http\Request::create('/api/v1/cleaning-bookings/'.$booking->id);
    $acceptedRequest->setUserResolver(fn () => $workerUser);
    $accepted = CleaningBookingResource::make($booking)->resolve($acceptedRequest);
    expect($accepted['workerCancellationPenaltyAmount'])
        ->toBe(CleaningFinancialSetting::currentCancellationFee());
    expect($accepted['supervisorContact'])->toBe([
        'id' => $supervisor->id,
        'name' => 'Visit Supervisor',
        'phone' => '0991234567',
    ]);

    $pendingRequest = \Illuminate\Http\Request::create('/api/v1/cleaning-bookings/'.$booking->id);
    $pendingRequest->setUserResolver(fn () => $otherWorkerUser);
    $pending = CleaningBookingResource::make($booking->fresh())->resolve($pendingRequest);
    expect($pending['supervisorContact'])->toBeNull();
});
