<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningFinancialSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Services\CleaningPricingCalculator;

it('calculates percentage administration margin from service costs without travel fees', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => CleaningFinancialSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'default_commission_rate' => 10,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'travel_per_km' => 7500,
        ],
    );

    $calculator = app(CleaningPricingCalculator::class);
    $pricing = $calculator->finalizedForCoordinates(
        basePrice: 100000,
        addonsTotal: 50000,
        a: 36.20,
        b: 37.15,
        c: 35.00,
        d: 39.00,
    );

    expect($pricing['travelFee'])->toBeGreaterThan(0)
        ->and($pricing['adminMargin'])->toBe(15000.0)
        ->and($pricing['totalPrice'])->toBe(
            $calculator->roundMoney(150000 + (float) $pricing['travelFee'] + 15000),
        );
});

it('keeps the worker eligible when at least one new order fits the administration capacity', function (): void {
    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 0,
            'restriction_threshold_percent' => 100,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 0,
        ],
    );

    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => CleaningFinancialSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'default_commission_rate' => 10,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'vat_rate' => 0,
            'travel_markup_type' => 'fixed',
            'travel_markup_value' => 0,
            'travel_per_km' => 7500,
            'travel_distance_start_point' => 'worker_home',
        ],
    );

    $user = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $user->id,
        'trust_score' => 80,
        'is_active' => true,
        'is_suspended' => false,
        'home_address' => 'Worker Home',
        'home_latitude' => 36.20,
        'home_longitude' => 37.15,
    ]);

    CleaningWorkerDeposit::query()->updateOrCreate(
        ['worker_id' => $worker->id],
        [
            'current_balance' => 15000,
            'debt_balance' => 0,
            'deposited_total' => 15000,
            'withdrawn_total' => 0,
            'minimum_required' => 0,
            'max_negative_balance' => 0,
            'is_active' => true,
        ],
    );

    $affordableBooking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'base_price' => 100000,
        'addons_total' => 0,
        'scheduled_date' => now()->addDay()->toDateString(),
        'scheduled_time' => '09:00',
        'gender_preference' => 'any',
        'address_latitude' => 36.1795,
        'address_longitude' => 37.1082,
    ]);

    $blockedBooking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::Pending->value,
        'worker_id' => null,
        'preferred_worker_id' => null,
        'base_price' => 500000,
        'addons_total' => 0,
        'scheduled_date' => now()->addDays(2)->toDateString(),
        'scheduled_time' => '09:00',
        'gender_preference' => 'any',
        'address_latitude' => 36.1795,
        'address_longitude' => 37.1082,
    ]);

    Sanctum::actingAs($user);

    $homepageResponse = $this->getJson('/api/v1/cleaning/worker/homepage');

    $homepageResponse->assertOk()
        ->assertJsonPath('administrationCapacityEligibility.canReceiveNewRequests', true)
        ->assertJsonPath('administrationCapacityEligibility.canAcceptNewBookings', true)
        ->assertJsonPath('administrationCapacityEligibility.reasonCode', 'eligible')
        ->assertJsonPath('administrationCapacityEligibility.availableNewOrdersCount', 1)
        ->assertJsonPath('administrationCapacityEligibility.blockedNewOrdersCount', 1)
        ->assertJsonPath('newOrdersCount', 1);

    $listResponse = $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending');

    $listResponse->assertOk();

    $bookingIds = collect($listResponse->json('data'))->pluck('id');

    expect($bookingIds)->toContain($affordableBooking->id)
        ->and($bookingIds)->not->toContain($blockedBooking->id);
});
