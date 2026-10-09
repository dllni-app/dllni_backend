<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningGeographicDispatchService;

it('expands dispatch from 10 km to 50 km at fifteen-minute intervals', function (): void {
    $service = app(CleaningGeographicDispatchService::class);
    foreach ([0 => 10, 14 => 10, 15 => 20, 29 => 20, 30 => 30, 45 => 40, 60 => 50, 130 => 50] as $minutes => $expectedKm) {
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
        'scheduled_date' => today()->addDay(),
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

it('bypasses radius for same-day urgent orders but not future regular orders', function (): void {
    $service = app(CleaningGeographicDispatchService::class);
    $worker = Worker::factory()->create([
        'home_latitude' => 35.0,
        'home_longitude' => 36.0,
    ]);
    $booking = CleaningBooking::factory()->create([
        'address_latitude' => 36.2,
        'address_longitude' => 37.15,
        'scheduled_date' => today(),
        'created_at' => now(),
    ]);

    expect($service->isWithinRadius($worker, $booking))->toBeTrue();

    $booking->scheduled_date = today()->addDay();
    expect($service->isWithinRadius($worker, $booking))->toBeFalse();
});


it('requeues radius expansion after fifteen minutes including same-day urgent bookings without GPS', function (): void {
    $base = [
        'worker_id' => null,
        'preferred_worker_id' => null,
        'status' => 'pending',
        'scheduled_date' => today()->addDay(),
        'address_latitude' => 36.2,
        'address_longitude' => 37.15,
    ];

    CleaningBooking::factory()->create(array_merge($base, ['created_at' => now()->subMinutes(14)]));
    CleaningBooking::factory()->create(array_merge($base, ['created_at' => now()->subMinutes(16)]));
    CleaningBooking::factory()->create(array_merge($base, [
        'created_at' => now()->subMinutes(16),
        'scheduled_date' => today(),
        'address_latitude' => null,
        'address_longitude' => null,
    ]));

    \\Illuminate\\Support\\Facades\\Bus::fake([\\App\\Jobs\\NotifyEligibleWorkersNewOrderJob::class]);

    \\Illuminate\\Support\\Facades\\Artisan::call('cleaning:expand-geographic-dispatch');

    \\Illuminate\\Support\\Facades\\Bus::assertDispatchedTimes(
        \\App\\Jobs\\NotifyEligibleWorkersNewOrderJob::class,
        2,
    );
});
