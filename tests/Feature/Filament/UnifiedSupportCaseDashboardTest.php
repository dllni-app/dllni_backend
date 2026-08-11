<?php

declare(strict_types=1);

use App\Enums\EmergencyType;
use App\Enums\SupportCaseKind;
use App\Enums\SupportCasePriority;
use App\Enums\SupportCaseReporterRole;
use App\Enums\SupportCaseStatus;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Filament\Resources\SosAlerts\SosAlertResource;
use App\Filament\Resources\SupportCases\SupportCaseResource;
use App\Models\SupportCase;
use App\Models\User;
use App\Models\Worker;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

beforeEach(function (): void {
    app()->setLocale('ar');
    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $adminRole = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($adminRole);

    $this->actingAs($admin);
});

it('registers only the unified support navigation entry', function (): void {
    expect(DisputeResource::shouldRegisterNavigation())->toBeFalse()
        ->and(SosAlertResource::shouldRegisterNavigation())->toBeFalse();

    $this->get(SupportCaseResource::getUrl('index', isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('البلاغات والنزاعات');
});

it('renders participant phones and OpenStreetMap for an emergency case', function (): void {
    $customer = User::factory()->create([
        'name' => 'أحمد العميل',
        'phone' => '+963944000222',
    ]);
    $workerUser = User::factory()->create([
        'name' => 'رنا العامل',
        'phone' => '+963900000001',
    ]);
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'first_name' => 'رنا العامل',
    ]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::InProgress,
        'booking_number' => 'CLN-SUPPORT-001',
    ]);
    $supportCase = SupportCase::query()->create([
        'case_number' => 'SOS-SUPPORT-001',
        'kind' => SupportCaseKind::Emergency,
        'priority' => SupportCasePriority::Critical,
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'reporter_id' => $customer->id,
        'reporter_role' => SupportCaseReporterRole::Customer,
        'category' => EmergencyType::SafetyThreat->value,
        'description' => 'بلاغ طوارئ يحتاج متابعة فورية.',
        'status' => SupportCaseStatus::New,
        'latitude' => 33.5138,
        'longitude' => 36.2765,
    ]);

    $this->get(SupportCaseResource::getUrl('view', ['record' => $supportCase], isAbsolute: false))
        ->assertSuccessful()
        ->assertSee('SOS-SUPPORT-001')
        ->assertSee('+963944000222')
        ->assertSee('+963900000001')
        ->assertSee('OpenStreetMap');
});
