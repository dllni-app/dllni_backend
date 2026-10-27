<?php

declare(strict_types=1);

use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Models\User;
use App\Models\Worker;
use Filament\Facades\Filament;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    app()->setLocale('ar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $admin = User::factory()->create(['is_active' => true]);
    $admin->assignRole(Role::findOrCreate('admin', 'web'));
    $this->actingAs($admin);
});

it('shows the exact V2 financial gap on the real booking detail page', function (): void {
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'number_of_workers' => 1,
    ]);
    $details = $booking->property_details ?? [];
    $details['_time_period_v2'] = ['pricing' => ['totalPrice' => 150]];
    $booking->forceFill([
        'property_details' => $details,
        'base_price' => 150,
        'total_price' => 150,
        'admin_margin_amount' => 0,
    ])->saveQuietly();
    $booking->workerAssignments()->create([
        'worker_id' => Worker::factory()->create()->id,
        'status' => CleaningBookingWorkerAssignmentStatus::AcceptedWaitingForOrderStart->value,
        'service_share_amount' => 150,
        'travel_fee' => 10,
        'admin_margin_amount' => 0,
        'worker_amount' => 160,
        'currency' => 'SYP',
    ]);

    $this->get(CleaningBookingResource::getUrl('view', ['record' => $booking], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('تحذير التسوية المالية')
        ->assertSee('فرق غير مموّل')
        ->assertSee('10.00');
});
