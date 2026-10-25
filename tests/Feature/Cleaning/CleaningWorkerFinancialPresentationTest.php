<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Worker;
use App\Models\CleaningFinancialSetting;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Http\Resources\CleaningBookingResource;
use Modules\Cleaning\Models\CleaningBooking;

it('exposes only the signed-in worker offer in additive financial and presentation envelopes', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(['id' => 1], [
        'default_commission_rate' => 10,
        'commission_type' => 'percent',
        'travel_per_km' => 1000,
        'travel_distance_start_point' => 'worker_home',
    ]);
    $customer = User::factory()->create();
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->financiallyEligible()->create([
        'user_id' => $workerUser->id,
        'trust_score' => 90,
        'open_disputes_count' => 0,
        'home_address' => 'Worker home',
        'home_latitude' => 33.5,
        'home_longitude' => 36.3,
    ]);
    $booking = CleaningBooking::factory()->create([
        'customer_id' => $customer->id,
        'status' => CleaningBookingStatus::Pending,
        'gender_preference' => 'any',
        'created_at' => now()->subMinutes(16),
        'worker_id' => null,
        'number_of_workers' => 1,
        'base_price' => 10000,
        'total_price' => 10000,
        'estimated_hours' => 4,
        'total_hours' => 4,
        'address_latitude' => 33.6,
        'address_longitude' => 36.3,
    ]);

    Sanctum::actingAs($workerUser);
    $workerPayload = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}")->assertOk()->json('data');

    expect($workerPayload['workerFinancials']['workerAmount'])
        ->toEqual((float) $workerPayload['workerOffer']['workerAmount'])
        ->and($workerPayload['workerFinancials']['currency'])->toBe($workerPayload['workerOffer']['currency'])
        ->and($workerPayload['workerPresentationState']['isAssigned'])->toBeFalse()
        ->and($workerPayload['workerPresentationState']['isOffer'])->toBeTrue();

    Sanctum::actingAs($customer);
    $customerPayload = CleaningBookingResource::make($booking)->resolve(request());
    expect($customerPayload['workerFinancials'])->toBeNull()
        ->and($customerPayload['workerPresentationState'])->toBeNull();
});
