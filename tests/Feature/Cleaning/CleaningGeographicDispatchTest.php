<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningGeographicDispatchService;

it('expands dispatch from 10 km to 50 km at twenty-minute intervals', function (): void {
    $service = app(CleaningGeographicDispatchService::class);
    foreach ([0 => 10, 19 => 10, 20 => 20, 39 => 20, 40 => 30, 60 => 40, 80 => 50, 130 => 50] as $minutes => $expectedKm) {
        $booking = CleaningBooking::factory()->create([
            'created_at' => now()->subMinutes($minutes),
            'address_latitude' => 36.2,
            'address_longitude' => 37.15,
        ]);

        expect($service->radiusKm($booking))->toBe($expectedKm);
    }
});

it('uses fresh worker GPS and falls back to mission start for stale GPS', function (): void {
    $worker = Worker::factory()->create([
        'home_latitude' => 36.2,
        'home_longitude' => 37.15,
    ]);
    $booking = CleaningBooking::factory()->create([
        'address_latitude' => 36.2,
        'address_longitude' => 37.15,
        'created_at' => now(),
    ]);

    $service = app(CleaningGeographicDispatchService::class);
    expect($service->isWithinRadius($worker, $booking))->toBeTrue();

    DB::table('worker_latest_locations')->insert([
        'worker_id' => $worker->id,
        'latitude' => 36.5,
        'longitude' => 37.5,
        'recorded_at' => now()->subMinutes(59),
        'updated_at' => now(),
    ]);

    expect($service->isWithinRadius($worker, $booking))->toBeFalse();

    DB::table('worker_latest_locations')->where('worker_id', $worker->id)
        ->update(['recorded_at' => now()->subMinutes(61)]);

    expect($service->isWithinRadius($worker, $booking))->toBeTrue();
});

it('accepts authenticated location reporting independently from bookings', function (): void {
    $user = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $user->id]);
    Sanctum::actingAs($user);

    $this->postJson('/api/v1/cleaning/worker/current-location', [
        'latitude' => 36.201,
        'longitude' => 37.151,
    ])->assertOk();

    $this->assertDatabaseHas('worker_latest_locations', [
        'worker_id' => $worker->id,
        'latitude' => 36.201,
        'longitude' => 37.151,
    ]);
});
