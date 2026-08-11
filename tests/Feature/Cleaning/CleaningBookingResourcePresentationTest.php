<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingRoom;

it('returns worker avatar and Arabic cleaning labels in booking payloads', function (): void {
    $disk = (string) (config('media-library.disk_name') ?: config('filesystems.default', 'public'));
    Storage::fake($disk);

    $viewer = User::factory()->create();
    $workerUser = User::factory()->create(['name' => 'Worker User']);
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    $worker->addMedia(UploadedFile::fake()->image('worker-avatar.jpg', 64, 64))
        ->toMediaCollection('avatar');

    $booking = CleaningBooking::factory()->create([
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

    $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");

    $response->assertOk()
        ->assertJsonPath('data.worker.avatarUrl', $worker->getFirstMediaUrl('avatar'))
        ->assertJsonPath('data.propertyDetails.cleaning_mode_label', 'تنظيف عميق')
        ->assertJsonPath('data.propertyDetails.living_room_size_label', 'كبيرة')
        ->assertJsonPath('data.roomAssignments.0.roomTypeLabel', 'غرفة نوم')
        ->assertJsonPath('data.roomAssignments.0.roomSizeLabel', 'كبيرة');
});
