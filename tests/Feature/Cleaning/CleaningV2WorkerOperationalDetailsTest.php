<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSpecialService;
use Modules\Cleaning\Models\CleaningSpecialService;

it('shows only operational special services from accepted worker visits', function (): void {
    $worker = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    $other = Worker::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard', 'number_of_workers' => 2, 'status' => 'worker_assigned',
    ]);
    $sessions = collect([1, 2])->map(fn (int $sequence): CleaningBookingSession => $booking->sessions()->create([
        'sequence' => $sequence, 'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task', 'scheduled_date' => now()->addDays($sequence)->toDateString(),
        'scheduled_time' => '09:00', 'duration_hours' => 2, 'required_workers' => 2,
        'coverage_status' => 'searching', 'status' => 'worker_assigned',
    ]));
    $sessions[0]->workerAssignments()->create(['worker_id' => $worker->id, 'status' => 'accepted', 'accepted_at' => now()]);
    $sessions[1]->workerAssignments()->create(['worker_id' => $other->id, 'status' => 'accepted', 'accepted_at' => now()]);
    foreach (['My service', 'Other service'] as $index => $name) {
        $catalogService = CleaningSpecialService::query()->create([
            'name' => $name, 'pricing_unit' => 'piece', 'base_unit_price' => 100,
            'estimated_duration_minutes' => 30, 'is_active' => true,
        ]);
        CleaningBookingSpecialService::query()->create([
            'cleaning_booking_id' => $booking->id,
            'cleaning_booking_session_id' => $sessions[$index]->id,
            'assigned_worker_id' => $index === 0 ? $worker->id : $other->id,
            'cleaning_special_service_id' => $catalogService->id,
            'service_name' => $name,
            'pricing_unit' => 'unit', 'dirtiness_level' => 'normal', 'quantity' => 1,
            'base_unit_price' => 100, 'price_multiplier' => 1, 'total_price' => 100,
            'execution_status' => 'pending',
        ])->sessions()->attach($sessions[$index]->id);
    }
    Sanctum::actingAs($worker->user);
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.specialServices.0.name', 'My service')
        ->assertJsonCount(1, 'data.specialServices')
        ->assertJsonStructure(['data' => ['materials', 'materialKit']]);
    Sanctum::actingAs($other->user);
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.specialServices.0.name', 'Other service')
        ->assertJsonCount(1, 'data.specialServices');
    $unassigned = Worker::factory()->create(['user_id' => User::factory()->create()->id]);
    Sanctum::actingAs($unassigned->user);
    $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertNotFound();
});
