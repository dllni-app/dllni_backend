<?php

declare(strict_types=1);

use App\Enums\DisputeCategory;
use App\Enums\DisputeStatus;
use App\Enums\EmergencyType;
use App\Enums\SOSStatus;
use App\Enums\UserModuleType;
use App\Filament\Resources\CleaningBookings\CleaningBookingResource;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Filament\Resources\SosAlerts\SosAlertResource;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Facades\Queue;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app()->setLocale('ar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    Queue::fake();

    $adminRole = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($adminRole);

    $this->actingAs($admin);
});

it('shows cleaning dispute phones and hides the removed resolution actions', function (): void {
    $customer = User::factory()->create([
        'name' => 'أحمد العميل',
        'phone' => '+963944000222',
    ]);
    $workerUser = User::factory()->create([
        'name' => 'رنا العامل',
        'phone' => '+963900000001',
        'module_type' => UserModuleType::CleaningWorker,
    ]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'first_name' => 'رنا',
    ]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::Completed,
        'booking_number' => 'CLN-AR-0001',
    ]);
    $dispute = $booking->disputes()->create([
        'ticket_number' => 'DSP-AR-0001',
        'description' => 'تفاصيل نزاع تنظيف للاختبار',
        'category' => DisputeCategory::PoorQuality->value,
        'status' => DisputeStatus::Open->value,
        'worker_earnings_frozen' => true,
    ]);

    $this->get(DisputeResource::getUrl('view', ['record' => $dispute], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('+963944000222')
        ->assertSee('+963900000001')
        ->assertSee('الخصم من العامل')
        ->assertDontSee('استرداد جزئي');
});

it('marks bookings with an open dispute and renders cleaning SOS details', function (): void {
    $customer = User::factory()->create([
        'phone' => '+963944000333',
    ]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => CleaningBookingStatus::Completed,
        'booking_number' => 'CLN-AR-0002',
    ]);
    $booking->disputes()->create([
        'ticket_number' => 'DSP-AR-0002',
        'description' => 'نزاع مفتوح',
        'category' => DisputeCategory::Other->value,
        'status' => DisputeStatus::UnderReview->value,
        'worker_earnings_frozen' => true,
    ]);
    $sosAlert = $booking->sosAlerts()->create([
        'user_id' => $customer->id,
        'emergency_type' => EmergencyType::SevereConflict->value,
        'message' => 'بلاغ طوارئ مرتبط بالحجز',
        'source' => 'user',
        'status' => SOSStatus::Triggered->value,
        'triggered_at' => now(),
    ]);

    $this->get(CleaningBookingResource::getUrl('index', isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('CLN-AR-0002')
        ->assertSee('يوجد نزاع مفتوح');

    $this->get(SosAlertResource::getUrl('view', ['record' => $sosAlert], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('بلاغ طوارئ مرتبط بالحجز');
});
