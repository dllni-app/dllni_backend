<?php

declare(strict_types=1);

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Models\User;
use App\Models\Worker;
use Filament\Facades\Filament;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Http\Resources\CleaningBookingResource as WorkerBookingResource;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningBookingTeamPresentation;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

it('presents accepted V2 visit workers rather than empty legacy booking assignments in API and Filament', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    app()->setLocale('ar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('admin', 'web'));

    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'number_of_workers' => 2,
        'status' => 'pending',
    ]);
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '09:00',
        'duration_hours' => 3.5,
        'required_workers' => 2,
        'coverage_status' => 'fully_covered',
        'status' => 'scheduled',
    ]);
    foreach ([1, 2] as $slot) {
        $session->workerAssignments()->create([
            'worker_id' => Worker::factory()->create()->id,
            'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
            'accepted_at' => now(),
            'service_share_amount' => 1450,
            'travel_fee' => 10,
            'admin_margin_amount' => 362.50,
            'worker_amount' => 1460,
            'currency' => 'SYP',
        ]);
    }
    expect($booking->acceptedWorkerCount())->toBe(0);
    $team = app(CleaningBookingTeamPresentation::class)->counts($booking);
    expect($team)->toMatchArray(['required' => 2, 'accepted' => 2, 'remaining' => 0]);

    $request = request();
    $request->setUserResolver(static fn () => $admin);
    $json = WorkerBookingResource::make($booking->fresh())->resolve($request);
    expect($json['accepted_workers_count'])->toBe(2)
        ->and($json['workerAcceptance']['isFulfilled'])->toBeTrue()
        ->and($json['pending_workers_count'])->toBe(0)
        ->and($json['workerLifecycleSummary']['accepted'])->toBe(2)
        ->and($json['workerLifecycleSummary']['acceptedWaitingForOrderStart'])->toBe(2);

    $this->actingAs($admin)
        ->get(CleaningBookingResource::getUrl('view', ['record' => $booking], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('2 من 2')
        ->assertDontSee('لا يوجد عامل مؤكد لهذا الحجز بعد');
});

it('excludes released visit workers and preserves legacy-only worker counting', function (): void {
    $booking = CleaningBooking::factory()->create(['booking_kind' => 'standard', 'number_of_workers' => 2]);
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'required_workers' => 2,
        'status' => 'scheduled',
    ]);
    $session->workerAssignments()->create([
        'worker_id' => Worker::factory()->create()->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'released_at' => now(),
    ]);
    $session->workerAssignments()->create([
        'worker_id' => Worker::factory()->create()->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
    ]);
    expect(app(CleaningBookingTeamPresentation::class)->counts($booking))
        ->toMatchArray(['required' => 2, 'accepted' => 1, 'remaining' => 1]);

    $legacy = CleaningBooking::factory()->create(['booking_kind' => 'standard', 'number_of_workers' => 2]);
    $legacy->workerAssignments()->create([
        'worker_id' => Worker::factory()->create()->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
    ]);
    expect(app(CleaningBookingTeamPresentation::class)->counts($legacy))
        ->toMatchArray(['required' => 2, 'accepted' => 1, 'remaining' => 1]);
});
