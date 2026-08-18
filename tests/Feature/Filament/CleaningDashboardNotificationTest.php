<?php

declare(strict_types=1);

use App\Enums\EmergencyType;
use App\Enums\SupportCaseKind;
use App\Enums\SupportCasePriority;
use App\Enums\SupportCaseReporterRole;
use App\Enums\SupportCaseStatus;
use App\Models\SupportCase;
use App\Models\User;
use App\Notifications\CleaningBookingStatusChangedDashboardNotification;
use App\Notifications\NewSupportCaseDashboardNotification;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

function createDashboardAdminForNotificationTest(): User
{
    app(PermissionRegistrar::class)->forgetCachedPermissions();
    $role = Role::findOrCreate('admin', 'web');
    $admin = User::factory()->create();
    $admin->assignRole($role);

    return $admin;
}

it('shows a bell notification when a new SOS support case is created', function (): void {
    $admin = createDashboardAdminForNotificationTest();
    $customer = User::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    SupportCase::query()->create([
        'case_number' => 'SOS-NOTIFY-001',
        'kind' => SupportCaseKind::Emergency,
        'priority' => SupportCasePriority::Critical,
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'reporter_id' => $customer->id,
        'reporter_role' => SupportCaseReporterRole::Customer,
        'category' => EmergencyType::SafetyThreat->value,
        'description' => 'Emergency dashboard notification test.',
        'status' => SupportCaseStatus::New,
    ]);

    $notification = $admin->notifications()
        ->where('type', NewSupportCaseDashboardNotification::class)
        ->latest()
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['title'] ?? null)->toBe('بلاغ طوارئ جديد (SOS)')
        ->and($notification->data['sound_type'] ?? null)->toBe('hard_alarm');
});

it('shows a bell notification when a new dispute support case is created', function (): void {
    $admin = createDashboardAdminForNotificationTest();
    $customer = User::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    SupportCase::query()->create([
        'case_number' => 'CMP-NOTIFY-001',
        'kind' => SupportCaseKind::Complaint,
        'priority' => SupportCasePriority::Normal,
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'reporter_id' => $customer->id,
        'reporter_role' => SupportCaseReporterRole::Customer,
        'category' => 'service_quality',
        'description' => 'Complaint dashboard notification test.',
        'status' => SupportCaseStatus::New,
    ]);

    $notification = $admin->notifications()
        ->where('type', NewSupportCaseDashboardNotification::class)
        ->latest()
        ->first();

    expect($notification)->not->toBeNull()
        ->and($notification->data['title'] ?? null)->toBe('نزاع جديد')
        ->and($notification->data['sound_type'] ?? null)->toBe('notify');
});

it('stores cleaning booking status change dashboard notifications in Arabic regardless of request locale', function (): void {
    app()->setLocale('en');

    $admin = User::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'booking_number' => 'CLN-NOTIFY-001',
        'status' => CleaningBookingStatus::UnderDispute,
    ]);

    $payload = (new CleaningBookingStatusChangedDashboardNotification(
        $booking,
        CleaningBookingStatus::Pending->value,
        CleaningBookingStatus::UnderDispute->value,
    ))->toDatabase($admin);

    expect($payload['title'] ?? null)->toBe('تغيرت حالة الحجز')
        ->and($payload['body'] ?? '')->toContain('قيد الانتظار')
        ->and($payload['body'] ?? '')->toContain('قيد النزاع')
        ->and($payload['body'] ?? '')->not->toContain('pending')
        ->and($payload['body'] ?? '')->not->toContain('under_dispute');
});

it('returns dispute details for the live dashboard alert poller', function (): void {
    $admin = createDashboardAdminForNotificationTest();
    $this->actingAs($admin);

    $customer = User::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    SupportCase::query()->create([
        'case_number' => 'CMP-LIVE-001',
        'kind' => SupportCaseKind::Complaint,
        'priority' => SupportCasePriority::Normal,
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'reporter_id' => $customer->id,
        'reporter_role' => SupportCaseReporterRole::Customer,
        'category' => 'service_quality',
        'description' => 'Live dispute notification.',
        'status' => SupportCaseStatus::New,
    ]);

    $this->getJson(route('admin.alert-sound-check'))
        ->assertSuccessful()
        ->assertJsonPath('soundType', 'notify')
        ->assertJsonPath('showBanner', true)
        ->assertJsonPath('title', 'نزاع جديد')
        ->assertJsonPath('notificationType', NewSupportCaseDashboardNotification::class);
});

it('returns a hard alarm payload for the live SOS alert poller', function (): void {
    $admin = createDashboardAdminForNotificationTest();
    $this->actingAs($admin);

    $customer = User::factory()->create();
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => CleaningBookingStatus::InProgress,
    ]);

    SupportCase::query()->create([
        'case_number' => 'SOS-LIVE-001',
        'kind' => SupportCaseKind::Emergency,
        'priority' => SupportCasePriority::Critical,
        'booking_id' => $booking->id,
        'booking_type' => 'cleaning_booking',
        'reporter_id' => $customer->id,
        'reporter_role' => SupportCaseReporterRole::Customer,
        'category' => EmergencyType::SafetyThreat->value,
        'description' => 'Live SOS notification.',
        'status' => SupportCaseStatus::New,
    ]);

    $this->getJson(route('admin.alert-sound-check'))
        ->assertSuccessful()
        ->assertJsonPath('soundType', 'hard_alarm')
        ->assertJsonPath('showBanner', true)
        ->assertJsonPath('title', 'بلاغ طوارئ جديد (SOS)')
        ->assertJsonPath('notificationType', NewSupportCaseDashboardNotification::class);
});
