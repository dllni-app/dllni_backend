<?php

declare(strict_types=1);

use App\Enums\EmergencyType;
use App\Enums\SOSStatus;
use App\Enums\UserModuleType;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Filament\Resources\SosAlerts\SosAlertResource;
use App\Filament\Resources\SosAlerts\Tables\SosAlertsTable;
use App\Models\SosAlert;
use App\Models\User;
use Modules\Cleaning\Models\CleaningBooking;

it('registers one cleaning support interface in the dashboard navigation', function (): void {
    expect(SosAlertResource::shouldRegisterNavigation())->toBeTrue()
        ->and(SosAlertResource::getNavigationLabel())->toBe('النزاعات والشكاوى')
        ->and(DisputeResource::shouldRegisterNavigation())->toBeFalse();
});

it('shows cleaning requests from both the user app and cleaning worker app', function (): void {
    $customer = User::factory()->create(['module_type' => null]);
    $workerUser = User::factory()->create(['module_type' => UserModuleType::CleaningWorker->value]);

    $customerBooking = CleaningBooking::factory()->create(['customer_id' => $customer->id]);
    $workerBooking = CleaningBooking::factory()->create();

    $customerRequest = SosAlert::query()->create([
        'user_id' => $customer->id,
        'booking_id' => $customerBooking->id,
        'booking_type' => CleaningBooking::class,
        'emergency_type' => EmergencyType::SafetyThreat->value,
        'message' => 'Customer request from dllni-user-app.',
        'source' => 'booking',
        'status' => SOSStatus::Triggered->value,
        'triggered_at' => now(),
    ]);

    $workerRequest = SosAlert::query()->create([
        'user_id' => $workerUser->id,
        'booking_id' => $workerBooking->id,
        'booking_type' => CleaningBooking::class,
        'emergency_type' => EmergencyType::MedicalEmergency->value,
        'message' => 'Worker request from cleaning_owner_app.',
        'source' => 'booking',
        'status' => SOSStatus::Triggered->value,
        'triggered_at' => now(),
    ]);

    $otherModuleRequest = SosAlert::query()->create([
        'user_id' => $customer->id,
        'booking_id' => 999999,
        'booking_type' => 'other_module',
        'emergency_type' => EmergencyType::SevereConflict->value,
        'message' => 'Request outside the cleaning apps.',
        'source' => 'booking',
        'status' => SOSStatus::Triggered->value,
        'triggered_at' => now(),
    ]);

    expect(SosAlertsTable::sourceAppLabel($customerRequest->load('user')))->toBe('تطبيق المستخدم')
        ->and(SosAlertsTable::sourceAppLabel($workerRequest->load('user')))->toBe('تطبيق عامل التنظيف');

    $visibleIds = SosAlertResource::getEloquentQuery()->pluck('id')->all();

    expect($visibleIds)
        ->toContain($customerRequest->id, $workerRequest->id)
        ->not->toContain($otherModuleRequest->id);
});
