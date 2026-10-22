<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Http\Middleware\EnsureSupervisor;
use Modules\Cleaning\Http\Middleware\RejectUnsupportedV2SupervisorExecution;
use Modules\Cleaning\Models\CleaningBooking;
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

it('holds booking-wide execution for a supervised standard V2 booking without changing state', function (): void {
    Role::findOrCreate('Cleaning Supervisor', 'web');
    $supervisor = User::factory()->create(['is_active' => true]);
    $supervisor->assignRole('Cleaning Supervisor');

    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'property_details' => ['_supervisor_pricing' => ['fee' => 100, 'hourlyRate' => 100]],
    ]);
    $booking->sessions()->create([
        'sequence' => 1,
        'calculation_mode' => 'task',
        'scheduled_date' => now()->addDay()->toDateString(),
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

    Sanctum::actingAs($supervisor);
    $this->postJson("/api/v1/supervisor/bookings/{$booking->id}/start")
        ->assertUnprocessable()
        ->assertJsonPath('code', 'SUPERVISOR_V2_EXECUTION_UNAVAILABLE');

    expect($booking->fresh()->work_started_at)->toBeNull();
});
