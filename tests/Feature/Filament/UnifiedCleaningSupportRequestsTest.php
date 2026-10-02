<?php

declare(strict_types=1);

use App\Enums\EmergencyType;
use App\Enums\SOSStatus;
use App\Enums\UserModuleType;
use App\Filament\Resources\Disputes\DisputeResource;
use App\Filament\Resources\SosAlerts\SosAlertResource;
use App\Filament\Resources\SosAlerts\Tables\SosAlertsTable;
use App\Filament\Resources\SupportCases\SupportCaseResource;
use App\Models\SosAlert;
use App\Models\User;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Supermarket\Models\SmOrder;

it('keeps legacy cleaning support resources out of navigation in favor of the unified support interface', function (): void {
    expect(SosAlertResource::shouldRegisterNavigation())->toBeFalse()
        ->and(DisputeResource::shouldRegisterNavigation())->toBeFalse()
        ->and(SupportCaseResource::getNavigationLabel())->toBe('البلاغات والنزاعات');
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

    $otherBooking = SmOrder::factory()->create(['customer_id' => $customer->id]);
    $otherModuleRequest = SosAlert::query()->create([
        'user_id' => $customer->id,
        'booking_id' => $otherBooking->id,
        'booking_type' => SmOrder::class,
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
