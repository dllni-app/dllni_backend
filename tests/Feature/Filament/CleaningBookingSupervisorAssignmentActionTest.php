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
    $firstVisitDate = now(config('app.timezone'))->addDays(4)->toDateString();
    $secondVisitDate = now(config('app.timezone'))->addDays(11)->toDateString();
    $booking = CleaningBooking::factory()->create(array_merge([
        'booking_kind' => 'standard',
        'status' => CleaningBookingStatus::WorkerAssigned,
        'number_of_workers' => 2,
        'scheduled_date' => $firstVisitDate,
        'scheduled_time' => '09:00',
        'property_details' => ['_supervisor_pricing' => ['hourlyRate' => 400, 'billableMinutes' => 240, 'fee' => 1600]],
    ], $attributes));

    foreach ([1 => $firstVisitDate, 2 => $secondVisitDate] as $visit => $date) {
        $session = $booking->sessions()->create([
            'sequence' => $visit,
            'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
            'calculation_mode' => 'task',
            'scheduled_date' => $date,
            'scheduled_time' => '09:00',
            'duration_hours' => 2,
            'required_workers' => 2,
            'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
            'supervision_initialized_at' => now(),
            'coverage_status' => 'searching',
            'status' => 'scheduled',
        ]);
        $booking->roomPeriods()->create([
            'cleaning_booking_session_id' => $session->id,
            'client_key' => 'bedroom.1', 'visit_sequence' => $visit,
            'room_type' => 'bedroom', 'room_size' => 'small', 'cleaning_mode' => 'regular',
            'scheduled_date' => $date, 'scheduled_time' => '09:00',
            'required_workers' => 2, 'estimated_duration_minutes' => 120,
        ]);
    }

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

    $this->assertDatabaseMissing('cleaning_supervisor_assignments', [
        'cleaning_booking_id' => $booking->id,
    ]);
    foreach ($booking->sessions as $session) {
        $this->assertDatabaseHas('cleaning_booking_session_supervisor_assignments', [
            'cleaning_booking_session_id' => $session->id,
            'supervisor_user_id' => $supervisor->id,
            'assigned_by_user_id' => $admin->id,
            'active_slot' => 1,
        ]);
    }
    expect(DB::table('cleaning_supervisor_activity')
        ->where('cleaning_booking_id', $booking->id)
        ->where('action', 'visit_supervisor_assigned')
        ->count())->toBe(2);

    Livewire::test(ViewCleaningBooking::class, ['record' => $booking->getRouteKey()])
        ->callAction('assign_supervisor', data: ['supervisor_user_id' => $inactive->id]);
    expect(DB::table('cleaning_booking_session_supervisor_assignments')->count())->toBe(2);
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
        $support, $booking->id, $supervisor->id, null, true, fromDashboard: true
    ))->toThrow(Symfony\Component\HttpKernel\Exception\HttpException::class);

    $this->actingAs($ops);
    Livewire::test(ViewCleaningBooking::class, ['record' => $booking->getRouteKey()])
        ->assertActionVisible('assign_supervisor')
        ->callAction('assign_supervisor', data: ['supervisor_user_id' => $supervisor->id])
        ->assertHasNoActionErrors();
    foreach ($booking->sessions as $session) {
        $this->assertDatabaseHas('cleaning_booking_session_supervisor_assignments', [
            'cleaning_booking_session_id' => $session->id,
            'assigned_by_user_id' => $ops->id,
            'supervisor_user_id' => $supervisor->id,
            'active_slot' => 1,
        ]);
    }
});

it('hides supervisor assignment on standard unscheduled and completed bookings but offers it for supervised team', function (): void {
    $this->actingAs(dashboardSupervisorActor('admin'));
    $solo = CleaningBooking::factory()->create(['booking_kind' => 'standard', 'number_of_workers' => 1]);
    $unscheduled = CleaningBooking::factory()->create(['booking_kind' => 'standard', 'number_of_workers' => 3]);
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

it('rejects overlapping V2 follow-up visits without leaving a second assignment', function (): void {
    $admin = dashboardSupervisorActor('admin');
    $supervisor = dashboardSupervisorActor('Cleaning Supervisor');
    $first = supervisorDashboardBooking();
    $overlapDate = $first->sessions()->where('sequence', 2)->value('scheduled_date');
    $laterDate = now(config('app.timezone'))->addDays(18)->toDateString();
    $overlap = supervisorDashboardBooking([
        'scheduled_date' => $overlapDate,
        'scheduled_time' => '09:30',
    ]);
    $overlap->roomPeriods()->where('visit_sequence', 1)
        ->update(['scheduled_date' => $overlapDate, 'scheduled_time' => '09:30']);
    $overlap->sessions()->where('sequence', 1)
        ->update(['scheduled_date' => $overlapDate, 'scheduled_time' => '09:30']);
    $overlap->roomPeriods()->where('visit_sequence', 2)
        ->update(['scheduled_date' => $laterDate, 'scheduled_time' => '09:30']);
    $overlap->sessions()->where('sequence', 2)
        ->update(['scheduled_date' => $laterDate, 'scheduled_time' => '09:30']);
    $this->actingAs($admin);

    Livewire::test(ViewCleaningBooking::class, ['record' => $first->getRouteKey()])
        ->callAction('assign_supervisor', data: ['supervisor_user_id' => $supervisor->id])
        ->assertHasNoActionErrors();
    Livewire::test(ViewCleaningBooking::class, ['record' => $overlap->getRouteKey()])
        ->callAction('assign_supervisor', data: ['supervisor_user_id' => $supervisor->id]);

    expect(DB::table('cleaning_booking_session_supervisor_assignments')->count())->toBe(2);
    foreach ($overlap->sessions as $session) {
        $this->assertDatabaseMissing('cleaning_booking_session_supervisor_assignments', [
            'cleaning_booking_session_id' => $session->id,
        ]);
    }
});

it('keeps repeated V2 booking header assignment idempotent in visit history', function (): void {
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

    expect(DB::table('cleaning_booking_session_supervisor_assignments')->count())->toBe(2);
    foreach ($booking->sessions as $session) {
        $this->assertDatabaseHas('cleaning_booking_session_supervisor_assignments', [
            'cleaning_booking_session_id' => $session->id,
            'supervisor_user_id' => $supervisor->id,
            'active_slot' => 1,
        ]);
    }
    expect(DB::table('cleaning_supervisor_activity')
        ->where('cleaning_booking_id', $booking->id)
        ->where('action', 'visit_supervisor_assigned')
        ->count())->toBe(2);
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
