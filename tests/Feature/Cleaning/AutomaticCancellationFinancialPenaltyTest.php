<?php

declare(strict_types=1);

use App\Models\CleaningFinancialPenalty;
use App\Models\CleaningFinancialSetting;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningCancellationFinancialPenaltyService;

it('automatically records a customer cancellation penalty that requires review', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 5,
            'vat_rate' => 0,
            'travel_markup_type' => 'fixed',
            'travel_markup_value' => 0,
            'user_cancellation_fee' => 18000,
        ],
    );

    $customer = User::factory()->create();
    Sanctum::actingAs($customer);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => CleaningBookingStatus::Pending->value,
        'cancellation_fee' => 0,
    ]);

    $booking->update([
        'status' => CleaningBookingStatus::Cancelled,
        'cancelled_at' => now(),
        'cancellation_reason' => 'Changed plans',
    ]);

    $penalty = CleaningFinancialPenalty::query()
        ->where('cleaning_booking_id', $booking->id)
        ->firstOrFail();

    expect($penalty->penalized_role)->toBe(CleaningFinancialPenalty::ROLE_CUSTOMER)
        ->and((int) $penalty->customer_id)->toBe((int) $customer->id)
        ->and($penalty->worker_id)->toBeNull()
        ->and((float) $penalty->amount)->toBe(18000.0)
        ->and($penalty->review_status)->toBe(CleaningFinancialPenalty::REVIEW_NEEDS_REVIEW)
        ->and($penalty->status)->toBe(CleaningFinancialPenalty::STATUS_ACTIVE)
        ->and((float) $booking->fresh()->cancellation_fee)->toBe(18000.0);
});

it('automatically charges a worker cancellation penalty and can review then cancel it', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 5,
            'vat_rate' => 0,
            'travel_markup_type' => 'fixed',
            'travel_markup_value' => 0,
            'user_cancellation_fee' => 12000,
        ],
    );

    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create(['user_id' => $workerUser->id]);
    Sanctum::actingAs($workerUser);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => User::factory(),
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned->value,
        'cancellation_fee' => 0,
        'total_price' => 50000,
    ]);

    $booking->update([
        'status' => CleaningBookingStatus::Cancelled,
        'cancelled_at' => now(),
        'cancellation_reason' => 'Worker unavailable',
    ]);

    $penalty = CleaningFinancialPenalty::query()
        ->where('cleaning_booking_id', $booking->id)
        ->firstOrFail();

    expect($penalty->penalized_role)->toBe(CleaningFinancialPenalty::ROLE_WORKER)
        ->and((int) $penalty->worker_id)->toBe((int) $worker->id)
        ->and($penalty->customer_id)->toBeNull()
        ->and((float) $penalty->amount)->toBe(12000.0)
        ->and($penalty->review_status)->toBe(CleaningFinancialPenalty::REVIEW_NEEDS_REVIEW)
        ->and($penalty->financial_transaction_id)->not->toBeNull()
        ->and((float) $worker->fresh()->deposit?->debt_balance)->toBe(12000.0);

    $admin = User::factory()->create();
    $service = app(CleaningCancellationFinancialPenaltyService::class);

    $service->markReviewed($penalty, $admin->id);
    $penalty->refresh();

    expect($penalty->review_status)->toBe(CleaningFinancialPenalty::REVIEW_REVIEWED)
        ->and((int) $penalty->reviewed_by_admin_id)->toBe((int) $admin->id);

    $service->cancelPenalty($penalty, $admin->id, 'Approved exception');
    $penalty->refresh();

    expect($penalty->status)->toBe(CleaningFinancialPenalty::STATUS_CANCELLED)
        ->and((int) $penalty->cancelled_by_admin_id)->toBe((int) $admin->id)
        ->and((float) $worker->fresh()->deposit?->debt_balance)->toBe(0.0);
});
