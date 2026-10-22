<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Http\Middleware\EnsureSupervisor;
use Modules\Cleaning\Http\Middleware\RejectUnsupportedV2SupervisorExecution;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionSupervisorAssignment;
use Modules\Cleaning\Models\CleaningRoomSchedule;
use Modules\Cleaning\Services\SupervisorScheduledV2Service;
use Spatie\Permission\Models\Role;

it('keeps the canonical supervisor V2 routes guarded and rejects stale competing aliases', function (): void {
    $routes = collect(Route::getRoutes()->getRoutes());
    foreach ([
        ['GET', 'api/v1/supervisor/v2/bookings'],
        ['GET', 'api/v1/supervisor/me'],
        ['POST', 'api/v1/supervisor/bookings/{booking}/start'],
    ] as [$method, $uri]) {
        $matches = $routes->filter(fn (LaravelRoute $route): bool => $route->uri() === $uri
            && in_array($method, $route->methods(), true));

        expect($matches)->toHaveCount(1);
        $middleware = $matches->first()->gatherMiddleware();
        expect($middleware)->toContain('auth:sanctum', EnsureSupervisor::class, RejectUnsupportedV2SupervisorExecution::class);
    }

    foreach ([
        'api/v1/supervisor/auth/login',
        'api/v1/user/cleaning/orders/{booking}/supervised/verification/code',
        'api/v1/admin/cleaning/supervised/bookings/{booking}/supervisor',
    ] as $staleAlias) {
        expect($routes->contains(fn (LaravelRoute $route): bool => $route->uri() === $staleAlias))->toBeFalse();
    }
});

it('preserves the current supervisor workflow schema instead of applying incompatible stale migrations', function (): void {
    expect(Schema::hasColumn('cleaning_supervisor_assignments', 'active_slot'))->toBeTrue()
        ->and(Schema::hasColumn('cleaning_supervisor_verifications', 'customer_code_ciphertext'))->toBeTrue()
        ->and(Schema::hasColumn('cleaning_supervisor_verifications', 'verified_at'))->toBeTrue()
        ->and(Schema::hasColumn('cleaning_supervisor_verifications', 'encrypted_code'))->toBeFalse()
        ->and(Schema::hasColumn('cleaning_supervisor_extensions', 'requested_minutes'))->toBeTrue()
        ->and(Schema::hasColumn('cleaning_supervisor_extensions', 'additional_minutes'))->toBeFalse()
        ->and(Schema::hasColumn('cleaning_booking_sessions', 'supervision_mode'))->toBeTrue();
});

it('holds booking-wide execution for an initialized, visit-owned V2 booking without changing state', function (): void {
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');

    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'property_details' => ['_supervisor_pricing' => ['fee' => 100, 'hourlyRate' => 100]],
    ]);
    $sessionDate = now('Asia/Damascus')->addDays(2)->toDateString();
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'calculation_mode' => 'task',
        'scheduled_date' => $sessionDate,
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(),
        'status' => 'scheduled',
    ]);
    $room = $booking->rooms()->create([
        'room_key' => 'integration.visit.1.room.1',
        'client_key' => 'integration.visit.1.room.1',
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'display_label' => 'Bedroom',
        'weight' => 1,
    ]);
    $start = CarbonImmutable::parse($sessionDate.' 09:00', 'Asia/Damascus')->utc();
    CleaningRoomSchedule::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_booking_session_id' => $session->id,
        'cleaning_booking_room_id' => $room->id,
        'schedule_key' => 'integration.visit.1.schedule.1',
        'visit_key' => 'integration.visit.1',
        'timezone' => 'Asia/Damascus',
        'starts_at' => $start,
        'ends_at' => $start->addHours(2),
        'required_workers' => 2,
        'work_minutes' => 240,
        'duration_minutes' => 120,
    ]);
    CleaningBookingSessionSupervisorAssignment::query()->create([
        'cleaning_booking_session_id' => $session->id,
        'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $supervisor->id,
        'assigned_at' => now(),
        'compensation_hourly_rate' => 20000,
        'active_slot' => 1,
    ]);

    // No booking-level assignment: the legacy OR fallback cannot produce this 422.
    expect($booking->supervisorAssignments()->count())->toBe(0)
        ->and($session->isSupervisionInitialized())->toBeTrue()
        ->and($session->activeSupervisorAssignment()->exists())->toBeTrue()
        ->and($booking->roomSchedules()->exists())->toBeTrue()
        ->and(app(SupervisorScheduledV2Service::class)->isEligible($booking))->toBeTrue()
        ->and(app(SupervisorScheduledV2Service::class)->assignedQuery($supervisor)->whereKey($booking->id)->exists())->toBeTrue();

    Sanctum::actingAs($supervisor);
    $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/start")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'SUPERVISOR_V2_EXECUTION_UNAVAILABLE');

    expect($booking->fresh()->work_started_at)->toBeNull()
        ->and($session->fresh()->work_started_at)->toBeNull();
});

it('independently holds legacy booking-assignment fallback for uninitialized V2 sessions', function (): void {
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');

    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'property_details' => ['_supervisor_pricing' => ['fee' => 100, 'hourlyRate' => 100]],
    ]);
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'calculation_mode' => 'task',
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'status' => 'scheduled',
    ]);
    DB::table('cleaning_supervisor_assignments')->insert([
        'cleaning_booking_id' => $booking->id,
        'supervisor_user_id' => $supervisor->id,
        'assigned_at' => now(),
        'active_slot' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect($session->isSupervisionInitialized())->toBeFalse()
        ->and($session->activeSupervisorAssignment()->exists())->toBeFalse()
        ->and(app(SupervisorScheduledV2Service::class)->assignedQuery($supervisor)->whereKey($booking->id)->exists())->toBeFalse();

    Sanctum::actingAs($supervisor);
    $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/start")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'SUPERVISOR_V2_EXECUTION_UNAVAILABLE');

    expect($booking->fresh()->work_started_at)->toBeNull();
});
