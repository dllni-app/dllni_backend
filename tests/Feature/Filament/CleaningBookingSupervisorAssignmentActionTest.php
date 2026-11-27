<?php

declare(strict_types=1);

use App\Filament\Resources\CleaningBookings\Pages\ViewCleaningBooking;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningEquipmentReservation;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;
use Modules\Cleaning\Services\CleaningSupervisorAssignmentService;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    app()->setLocale('ar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function dashboardSupervisorActor(string $role, bool $permissions = false): User
{
    $record = Role::findOrCreate($role, 'web');
    if ($permissions) {
        foreach (['bookings.view', 'bookings.update'] as $permission) {
            $record->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
    }
    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($record);

    return $user;
}

function supervisorDashboardBooking(array $attributes = []): CleaningBooking
{
    // V2 persists one booking per appointment, not an obsolete roomPeriods
    // group. Exercise the actual booking-level supervisor assignment boundary.
    $date = now(config('app.timezone'))->addDays(4)->toDateString();
    $booking = CleaningBooking::factory()->create(array_merge([
        'contract_version' => 'v2',
        'booking_kind' => 'standard',
        'status' => CleaningBookingStatus::WorkerAssigned,
        'number_of_workers' => 2,
        'estimated_hours' => 4,
        'scheduled_date' => $date,
        'scheduled_time' => '09:00',
        'property_details' => ['_supervisor_pricing' => ['hourlyRate' => 400, 'billableMinutes' => 120, 'fee' => 800]],
    ], $attributes));

    $booking->sessions()->create([
            'sequence' => 1,
            'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
            'calculation_mode' => 'task',
            'scheduled_date' => $booking->scheduled_date->toDateString(),
            'scheduled_time' => $booking->scheduled_time,
            'duration_hours' => 2,
            'required_workers' => 2,
            'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
            'supervision_initialized_at' => now(),
            'coverage_status' => 'searching',
            'status' => 'scheduled',
    ]);

    return $booking;
}

function dashboardSupervisorAssignment(User $supervisor, CleaningBooking $booking): void
{
    DB::table('cleaning_supervisor_assignments')->insert([
        'cleaning_booking_id' => $booking->id, 'supervisor_user_id' => $supervisor->id,
        'active_slot' => 1, 'assigned_at' => now(), 'created_at' => now(), 'updated_at' => now(),
    ]);
}

it('assigns an active supervisor from the Filament booking header and audits a V2 assignment', function (): void {
    $admin = dashboardSupervisorActor('admin');
    $supervisor = dashboardSupervisorActor('Cleaning Supervisor');
    $inactive = dashboardSupervisorActor('Cleaning Supervisor');
    $inactive->forceFill(['is_active' => false])->save();
    $booking = supervisorDashboardBooking();
    $this->actingAs($admin);

    Livewire::test(ViewCleaningBooking::class, ['record' => $booking->getRouteKey()])
        ->assertActionVisible('assign_supervisor')
        ->callAction('assign_supervisor', data: ['supervisor_user_id' => $supervisor->id])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas('cleaning_supervisor_assignments', [
        'cleaning_booking_id' => $booking->id,
        'supervisor_user_id' => $supervisor->id,
        'assigned_by_user_id' => $admin->id,
        'active_slot' => 1,
    ]);
    expect(DB::table('cleaning_supervisor_activity')
        ->where('cleaning_booking_id', $booking->id)
        ->where('action', 'supervisor_assigned')
        ->count())->toBe(1);

    Livewire::test(ViewCleaningBooking::class, ['record' => $booking->getRouteKey()])
        ->callAction('assign_supervisor', data: ['supervisor_user_id' => $inactive->id]);
    expect(DB::table('cleaning_supervisor_assignments')->where('cleaning_booking_id', $booking->id)->count())->toBe(1);
    $this->assertDatabaseMissing('cleaning_supervisor_assignments', ['cleaning_booking_id' => $booking->id, 'supervisor_user_id' => $inactive->id]);
});

it('allows an ops manager with booking update permission and hides the action from support staff', function (): void {
    $ops = dashboardSupervisorActor('Cleaning Ops Manager', permissions: true);
    $support = dashboardSupervisorActor('Customer Support', permissions: true);
    $supervisor = dashboardSupervisorActor('Cleaning Supervisor');
    $booking = supervisorDashboardBooking();

    $this->actingAs($support);
    Livewire::test(ViewCleaningBooking::class, ['record' => $booking->getRouteKey()])
        ->assertActionHidden('assign_supervisor');
    expect(fn () => app(CleaningSupervisorAssignmentService::class)->assign(
        $support, $booking->id, $supervisor->id, null, fromDashboard: true
    ))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);

    $this->actingAs($ops);
    Livewire::test(ViewCleaningBooking::class, ['record' => $booking->getRouteKey()])
        ->assertActionVisible('assign_supervisor')
        ->callAction('assign_supervisor', data: ['supervisor_user_id' => $supervisor->id])
        ->assertHasNoActionErrors();
    $this->assertDatabaseHas('cleaning_supervisor_assignments', [
        'cleaning_booking_id' => $booking->id,
        'assigned_by_user_id' => $ops->id,
        'supervisor_user_id' => $supervisor->id,
        'active_slot' => 1,
    ]);
});

it('hides supervisor assignment on standard unscheduled and completed bookings but offers it for supervised team', function (): void {
    $this->actingAs(dashboardSupervisorActor('admin'));
    $solo = CleaningBooking::factory()->create(['booking_kind' => 'standard', 'number_of_workers' => 1, 'status' => CleaningBookingStatus::Pending]);
    // The schema requires date/time; a zero planned workload is not a valid
    // schedulable standard team, even when a placeholder date exists.
    $unscheduled = CleaningBooking::factory()->create(['booking_kind' => 'standard', 'number_of_workers' => 3, 'estimated_hours' => 0, 'status' => CleaningBookingStatus::Pending]);
    $completed = supervisorDashboardBooking(['status' => CleaningBookingStatus::Completed]);
    $legacy = CleaningBooking::factory()->create([
        'booking_kind' => 'supervised_team', 'status' => CleaningBookingStatus::WorkerAssigned,
    ]);

    foreach ([$solo, $unscheduled, $completed] as $booking) {
        Livewire::test(ViewCleaningBooking::class, ['record' => $booking->getRouteKey()])
            ->assertActionHidden('assign_supervisor');
    }
    Livewire::test(ViewCleaningBooking::class, ['record' => $legacy->getRouteKey()])
        ->assertActionVisible('assign_supervisor');
});

it('rejects overlapping independent V2 follow-up bookings without leaving a second assignment', function (): void {
    $admin = dashboardSupervisorActor('admin');
    $supervisor = dashboardSupervisorActor('Cleaning Supervisor');
    $first = supervisorDashboardBooking();
    $overlapDate = now(config('app.timezone'))->addDays(11)->toDateString();
    $followUp = supervisorDashboardBooking(['scheduled_date' => $overlapDate, 'customer_id' => $first->customer_id]);
    $overlap = supervisorDashboardBooking([
        'scheduled_date' => $overlapDate,
        'scheduled_time' => '09:30',
    ]);
    $this->actingAs($admin);

    Livewire::test(ViewCleaningBooking::class, ['record' => $first->getRouteKey()])
        ->callAction('assign_supervisor', data: ['supervisor_user_id' => $supervisor->id])
        ->assertHasNoActionErrors();
    Livewire::test(ViewCleaningBooking::class, ['record' => $followUp->getRouteKey()])
        ->callAction('assign_supervisor', data: ['supervisor_user_id' => $supervisor->id])
        ->assertHasNoActionErrors();
    Livewire::test(ViewCleaningBooking::class, ['record' => $overlap->getRouteKey()])
        ->callAction('assign_supervisor', data: ['supervisor_user_id' => $supervisor->id]);

    expect(DB::table('cleaning_supervisor_assignments')->where('supervisor_user_id', $supervisor->id)->count())->toBe(2);
    $this->assertDatabaseMissing('cleaning_supervisor_assignments', ['cleaning_booking_id' => $overlap->id]);
});

it('keeps repeated V2 booking header assignment idempotent in booking history', function (): void {
    $admin = dashboardSupervisorActor('admin');
    $supervisor = dashboardSupervisorActor('Cleaning Supervisor');
    $booking = supervisorDashboardBooking();
    $this->actingAs($admin);

    Livewire::test(ViewCleaningBooking::class, ['record' => $booking->getRouteKey()])
        ->callAction('assign_supervisor', data: ['supervisor_user_id' => $supervisor->id])
        ->assertHasNoActionErrors();
    Livewire::test(ViewCleaningBooking::class, ['record' => $booking->getRouteKey()])
        ->callAction('assign_supervisor', data: ['supervisor_user_id' => $supervisor->id])
        ->assertHasNoActionErrors();

    expect(DB::table('cleaning_supervisor_assignments')->where('cleaning_booking_id', $booking->id)->count())->toBe(1);
    $this->assertDatabaseHas('cleaning_supervisor_assignments', [
        'cleaning_booking_id' => $booking->id,
        'supervisor_user_id' => $supervisor->id,
        'active_slot' => 1,
    ]);
    expect(DB::table('cleaning_supervisor_activity')
        ->where('cleaning_booking_id', $booking->id)
        ->where('action', 'supervisor_assigned')
        ->count())->toBe(1);
});

it('blocks transferring supervised-team equipment until the checkout has a confirmed return', function (): void {
    $admin = dashboardSupervisorActor('admin');
    $first = dashboardSupervisorActor('Cleaning Supervisor');
    $second = dashboardSupervisorActor('Cleaning Supervisor');
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'supervised_team', 'status' => CleaningBookingStatus::WorkerAssigned,
        'number_of_workers' => 2, 'scheduled_date' => '2026-10-25',
        'scheduled_time' => '09:00', 'estimated_hours' => 4,
    ]);
    dashboardSupervisorAssignment($first, $booking);
    $asset = CleaningSpecialServiceEquipment::query()->create([
        'name' => 'معدة للمشرف', 'is_active' => true, 'status' => 'in_use',
    ]);
    $reservation = CleaningEquipmentReservation::query()->create([
        'cleaning_booking_id' => $booking->id,
        'cleaning_special_service_equipment_id' => $asset->id,
        'supervisor_user_id' => $first->id,
        'status' => 'acknowledged', 'handed_over_at' => now(),
        'reserved_from' => now(), 'reserved_until' => now()->addHours(3),
    ]);
    $this->actingAs($admin);
    $reason = 'نقل الإشراف بسبب تبديل ورديات الفريق';

    Livewire::test(ViewCleaningBooking::class, ['record' => $booking->getRouteKey()])
        ->callAction('assign_supervisor', data: ['supervisor_user_id' => $second->id, 'reason' => $reason]);
    $this->assertDatabaseCount('cleaning_supervisor_assignments', 1);

    $reservation->forceFill(['return_confirmed_at' => now(), 'status' => 'returned'])->save();
    Livewire::test(ViewCleaningBooking::class, ['record' => $booking->getRouteKey()])
        ->callAction('assign_supervisor', data: ['supervisor_user_id' => $second->id, 'reason' => $reason])
        ->assertHasNoActionErrors();
    $this->assertDatabaseHas('cleaning_supervisor_assignments', [
        'cleaning_booking_id' => $booking->id, 'supervisor_user_id' => $second->id, 'active_slot' => 1,
    ]);
});
