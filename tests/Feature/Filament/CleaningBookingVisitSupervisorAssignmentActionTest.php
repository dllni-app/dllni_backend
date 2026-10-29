<?php

declare(strict_types=1);

use App\Filament\Resources\CleaningBookings\Pages\ViewCleaningBooking;
use App\Filament\Resources\CleaningBookings\RelationManagers\SessionsRelationManager;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingMaterialKit;
use Modules\Cleaning\Models\CleaningBookingSession;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    app()->setLocale('ar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

function visitSupervisorActor(string $role, bool $bookingPermissions = false): User
{
    $roleModel = Role::findOrCreate($role, 'web');
    if ($bookingPermissions) {
        foreach (['bookings.view', 'bookings.update'] as $permission) {
            $roleModel->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
    }

    $user = User::factory()->create(['is_active' => true]);
    $user->assignRole($roleModel);

    return $user;
}

/** @return array{0:CleaningBooking,1:CleaningBookingSession} */
function visitSupervisorBooking(?string $date = null, string $time = '09:00'): array
{
    $date ??= now(config('app.timezone'))->addDays(4)->toDateString();
    $booking = CleaningBooking::factory()->create([
        'booking_kind' => 'standard',
        'status' => CleaningBookingStatus::WorkerAssigned,
        'number_of_workers' => 2,
        'scheduled_date' => $date,
        'scheduled_time' => $time,
        'property_details' => [
            '_supervisor_pricing' => ['hourlyRate' => 0, 'billableMinutes' => 120, 'fee' => 0],
        ],
    ]);
    $session = $booking->sessions()->create([
        'sequence' => 1,
        'session_type' => CleaningBookingSession::TYPE_RECURRING_CLEANING,
        'calculation_mode' => 'task',
        'scheduled_date' => $date,
        'scheduled_time' => $time,
        'duration_hours' => 2,
        'required_workers' => 2,
        'supervision_mode' => CleaningBookingSession::SUPERVISION_MODE_SUPERVISOR,
        'supervision_initialized_at' => now(),
        'coverage_status' => 'searching',
        'status' => 'scheduled',
    ]);
    $booking->roomPeriods()->create([
        'cleaning_booking_session_id' => $session->id,
        'client_key' => 'visit-supervisor-room',
        'visit_sequence' => 1,
        'room_type' => 'bedroom',
        'room_size' => 'small',
        'cleaning_mode' => 'regular',
        'scheduled_date' => $date,
        'scheduled_time' => $time,
        'required_workers' => 2,
        'estimated_duration_minutes' => 120,
    ]);

    return [$booking->fresh(), $session->fresh()];
}

function visitSupervisorAction(CleaningBookingSession $session): TestAction
{
    return TestAction::make('assign_visit_supervisor')->table($session);
}

it('shows visit supervisor assignment only to authorized Filament operations actors', function (): void {
    $support = visitSupervisorActor('Customer Support', bookingPermissions: true);
    $ops = visitSupervisorActor('Cleaning Ops Manager', bookingPermissions: true);
    [$booking, $session] = visitSupervisorBooking();

    $this->actingAs($support);
    Livewire::test(SessionsRelationManager::class, [
        'ownerRecord' => $booking,
        'pageClass' => ViewCleaningBooking::class,
    ])->assertActionHidden(visitSupervisorAction($session));

    $this->actingAs($ops);
    Livewire::test(SessionsRelationManager::class, [
        'ownerRecord' => $booking,
        'pageClass' => ViewCleaningBooking::class,
    ])->assertActionVisible(visitSupervisorAction($session))
        ->assertTableColumnExists('visit_supervisor');
});

it('hides visit supervisor assignment when the parent booking is terminal', function (): void {
    $ops = visitSupervisorActor('Cleaning Ops Manager', bookingPermissions: true);
    [$booking, $session] = visitSupervisorBooking();
    $booking->forceFill(['status' => CleaningBookingStatus::Completed])->save();
    $this->actingAs($ops);

    Livewire::test(SessionsRelationManager::class, [
        'ownerRecord' => $booking->fresh(),
        'pageClass' => ViewCleaningBooking::class,
    ])->assertActionHidden(visitSupervisorAction($session));
});

it('records visit assignment history and requires a reason before reassignment', function (): void {
    $ops = visitSupervisorActor('Cleaning Ops Manager', bookingPermissions: true);
    $first = visitSupervisorActor('Cleaning Supervisor');
    $second = visitSupervisorActor('Cleaning Supervisor');
    [$booking, $session] = visitSupervisorBooking();
    $this->actingAs($ops);

    Livewire::test(SessionsRelationManager::class, [
        'ownerRecord' => $booking,
        'pageClass' => ViewCleaningBooking::class,
    ])->callAction(visitSupervisorAction($session), data: ['supervisor_user_id' => $first->id])
        ->assertHasNoActionErrors();

    $this->assertDatabaseHas('cleaning_booking_session_supervisor_assignments', [
        'cleaning_booking_session_id' => $session->id,
        'supervisor_user_id' => $first->id,
        'assigned_by_user_id' => $ops->id,
        'active_slot' => 1,
    ]);
    $this->assertDatabaseHas('cleaning_supervisor_activity', [
        'cleaning_booking_id' => $booking->id,
        'actor_user_id' => $ops->id,
        'action' => 'visit_supervisor_assigned',
    ]);

    Livewire::test(SessionsRelationManager::class, [
        'ownerRecord' => $booking,
        'pageClass' => ViewCleaningBooking::class,
    ])->callAction(visitSupervisorAction($session), data: [
        'supervisor_user_id' => $second->id,
        'reason' => '',
    ])->assertHasActionErrors(['reason']);

    $reason = 'تغيير مشرف الزيارة بسبب تعارض جدول الوردية الحالية';
    Livewire::test(SessionsRelationManager::class, [
        'ownerRecord' => $booking,
        'pageClass' => ViewCleaningBooking::class,
    ])->callAction(visitSupervisorAction($session), data: [
        'supervisor_user_id' => $second->id,
        'reason' => $reason,
    ])->assertHasNoActionErrors();

    $this->assertDatabaseHas('cleaning_booking_session_supervisor_assignments', [
        'cleaning_booking_session_id' => $session->id,
        'supervisor_user_id' => $first->id,
        'active_slot' => null,
        'reason' => $reason,
    ]);
    $this->assertDatabaseHas('cleaning_booking_session_supervisor_assignments', [
        'cleaning_booking_session_id' => $session->id,
        'supervisor_user_id' => $second->id,
        'active_slot' => 1,
    ]);
});

it('blocks an overlapping visit assignment and a transfer while materials remain in custody', function (): void {
    $ops = visitSupervisorActor('Cleaning Ops Manager', bookingPermissions: true);
    $supervisor = visitSupervisorActor('Cleaning Supervisor');
    $replacement = visitSupervisorActor('Cleaning Supervisor');
    $date = now(config('app.timezone'))->addDays(5)->toDateString();
    [$firstBooking, $firstSession] = visitSupervisorBooking($date, '09:00');
    [$overlapBooking, $overlapSession] = visitSupervisorBooking($date, '10:00');
    $this->actingAs($ops);

    Livewire::test(SessionsRelationManager::class, [
        'ownerRecord' => $firstBooking,
        'pageClass' => ViewCleaningBooking::class,
    ])->callAction(visitSupervisorAction($firstSession), data: ['supervisor_user_id' => $supervisor->id])
        ->assertHasNoActionErrors();

    Livewire::test(SessionsRelationManager::class, [
        'ownerRecord' => $overlapBooking,
        'pageClass' => ViewCleaningBooking::class,
    ])->callAction(visitSupervisorAction($overlapSession), data: ['supervisor_user_id' => $supervisor->id]);
    $this->assertDatabaseMissing('cleaning_booking_session_supervisor_assignments', [
        'cleaning_booking_session_id' => $overlapSession->id,
        'supervisor_user_id' => $supervisor->id,
    ]);

    CleaningBookingMaterialKit::query()->create([
        'cleaning_booking_id' => $firstBooking->id,
        'status' => CleaningBookingMaterialKit::RECEIVED,
        'received_at' => now(),
        'received_by_supervisor_user_id' => $supervisor->id,
    ]);
    $reason = 'نقل الإشراف بعد تعديل خطة الفريق للزيارة القادمة';
    Livewire::test(SessionsRelationManager::class, [
        'ownerRecord' => $firstBooking,
        'pageClass' => ViewCleaningBooking::class,
    ])->callAction(visitSupervisorAction($firstSession), data: [
        'supervisor_user_id' => $replacement->id,
        'reason' => $reason,
    ]);

    expect(DB::table('cleaning_booking_session_supervisor_assignments')
        ->where('cleaning_booking_session_id', $firstSession->id)
        ->where('active_slot', 1)
        ->value('supervisor_user_id'))->toBe($supervisor->id);
    $this->assertDatabaseMissing('cleaning_booking_session_supervisor_assignments', [
        'cleaning_booking_session_id' => $firstSession->id,
        'supervisor_user_id' => $replacement->id,
    ]);
});
