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

it('shows cleaning requests from users and cleaning workers in one interface', function (): void {
    $customer = User::factory()->create(['module_type' => null]);
    $workerUser = User::factory()->create(['module_type' => UserModuleType::CleaningWorker->value]);

    $customerBooking = CleaningBooking::factory()->create(['customer_id' => $customer->id]);
    $workerBooking = CleaningBooking::factory()->create();

    $customerRequest = SosAlert::query()->create([
        'user_id' => $customer->id,
        'booking_id' => $customerBooking->id,
        'booking_type' => CleaningBooking::class,
        'emergency_type' => EmergencyType::SafetyThreat->value,
        'message' => 'Customer cleaning request.',
        'source' => 'booking',
        'status' => SOSStatus::Triggered->value,
        'triggered_at' => now(),
    ]);

    $workerRequest = SosAlert::query()->create([
        'user_id' => $workerUser->id,
        'booking_id' => $workerBooking->id,
        'booking_type' => CleaningBooking::class,
        'emergency_type' => EmergencyType::MedicalEmergency->value,
        'message' => 'Worker cleaning request.',
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

    expect(SosAlertsTable::roleLabel($customerRequest->load('user')))->toBe('مستخدم')
        ->and(SosAlertsTable::roleLabel($workerRequest->load('user')))->toBe('عامل تنظيف');

    $visibleIds = SosAlertResource::getEloquentQuery()->pluck('id')->all();

    expect($visibleIds)
        ->toContain($customerRequest->id, $workerRequest->id)
        ->not->toContain($otherModuleRequest->id);
});
