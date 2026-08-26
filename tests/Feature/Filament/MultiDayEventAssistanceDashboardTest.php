<?php

declare(strict_types=1);

use App\Filament\Resources\CleaningBookings\Pages\ListCleaningBookings;
use App\Models\User;
use Livewire\Livewire;
use Modules\Cleaning\Enums\CleaningBookingSessionStatus;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningBookingSession;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $admin = User::factory()->create(['email' => 'multi-day-event-dashboard@example.com']);
    $admin->assignRole('admin');
    $this->actingAs($admin);
});

it('includes a multi-day event in scheduled today when any session is today', function (): void {
    $booking = CleaningBooking::factory()->create([
        'property_type' => 'event_assistance',
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'scheduled_date' => now()->subDay()->toDateString(),
        'scheduled_time' => '18:00',
    ]);
    CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => 1,
        'scheduled_date' => now()->subDay()->toDateString(),
        'scheduled_time' => '18:00',
        'duration_hours' => 2,
        'status' => CleaningBookingSessionStatus::Completed->value,
        'work_finished_at' => now()->subHours(2),
    ]);
    CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $booking->id,
        'sequence' => 2,
        'scheduled_date' => today()->toDateString(),
        'scheduled_time' => '18:00',
        'duration_hours' => 3,
        'status' => CleaningBookingSessionStatus::WorkerAssigned->value,
    ]);

    $notToday = CleaningBooking::factory()->create([
        'property_type' => 'event_assistance',
        'scheduled_date' => now()->addDays(4)->toDateString(),
        'scheduled_time' => '12:00',
    ]);
    CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $notToday->id,
        'sequence' => 1,
        'scheduled_date' => now()->addDays(4)->toDateString(),
        'scheduled_time' => '12:00',
        'duration_hours' => 2,
        'status' => CleaningBookingSessionStatus::Scheduled->value,
    ]);

    Livewire::test(ListCleaningBookings::class)
        ->filterTable('scheduled_today')
        ->assertCanSeeTableRecords([$booking])
        ->assertCanNotSeeTableRecords([$notToday]);
});

it('filters multi-day events without matching single-session event assistance', function (): void {
    $multiDay = CleaningBooking::factory()->create([
        'property_type' => 'event_assistance',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '18:00',
    ]);
    foreach ([1, 2] as $sequence) {
        CleaningBookingSession::query()->create([
            'cleaning_booking_id' => $multiDay->id,
            'sequence' => $sequence,
            'scheduled_date' => now()->addDays($sequence)->toDateString(),
            'scheduled_time' => '18:00',
            'duration_hours' => 2,
            'status' => CleaningBookingSessionStatus::Scheduled->value,
        ]);
    }

    $singleDay = CleaningBooking::factory()->create([
        'property_type' => 'event_assistance',
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
    ]);
    CleaningBookingSession::query()->create([
        'cleaning_booking_id' => $singleDay->id,
        'sequence' => 1,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '10:00',
        'duration_hours' => 2,
        'status' => CleaningBookingSessionStatus::Scheduled->value,
    ]);

    Livewire::test(ListCleaningBookings::class)
        ->filterTable('multi_day_event')
        ->assertCanSeeTableRecords([$multiDay])
        ->assertCanNotSeeTableRecords([$singleDay]);
});
