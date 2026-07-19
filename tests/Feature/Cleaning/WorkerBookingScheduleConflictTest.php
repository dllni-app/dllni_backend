<?php

declare(strict_types=1);

use App\Models\CleaningDepositSetting;
use App\Models\CleaningFinancialSetting;
use App\Models\CleaningWorkerDeposit;
use App\Models\User;
use App\Models\Worker;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

beforeEach(function (): void {
    Carbon::setTestNow('2026-07-20 08:00:00');

    CleaningDepositSetting::query()->updateOrCreate(
        ['id' => CleaningDepositSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'minimum_deposit_amount' => 0,
            'default_max_negative_balance' => 0,
            'is_enabled' => true,
            'trust_reject_after_accept_penalty' => 10,
            'trust_minimum_for_dispatch' => 0,
        ],
    );

    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => CleaningFinancialSetting::query()->orderBy('id')->value('id') ?? 1],
        [
            'default_commission_rate' => 10.00,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'vat_rate' => 0.00,
            'travel_markup_type' => 'fixed',
            'travel_markup_value' => 0.00,
            'travel_per_km' => 0.00,
            'travel_distance_start_point' => 'worker_home',
        ],
    );
});

afterEach(function (): void {
    Carbon::setTestNow();
});

it('hides pending cleaning requests that overlap a confirmed worker booking from the home list and count', function (): void {
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'trust_score' => 100,
        'is_active' => true,
        'is_suspended' => false,
        'home_address' => 'Worker Home',
        'home_latitude' => 33.50,
        'home_longitude' => 36.30,
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 100000,
        'deposited_total' => 100000,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 0,
    ]);

    Sanctum::actingAs($workerUser);

    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'scheduled_date' => '2026-07-20',
        'scheduled_time' => '09:00',
        'total_hours' => 3,
        'estimated_hours' => 3,
        'gender_preference' => 'any',
    ]);

    $overlappingBooking = CleaningBooking::factory()->create([
        'worker_id' => null,
        'preferred_worker_id' => null,
        'status' => CleaningBookingStatus::Pending,
        'scheduled_date' => '2026-07-20',
        'scheduled_time' => '11:00',
        'total_hours' => 2,
        'estimated_hours' => 2,
        'gender_preference' => 'any',
        'base_price' => 100,
        'addons_total' => 0,
        'address_latitude' => 33.51,
        'address_longitude' => 36.31,
    ]);

    $nonOverlappingBooking = CleaningBooking::factory()->create([
        'worker_id' => null,
        'preferred_worker_id' => null,
        'status' => CleaningBookingStatus::Pending,
        'scheduled_date' => '2026-07-20',
        'scheduled_time' => '12:00',
        'total_hours' => 2,
        'estimated_hours' => 2,
        'gender_preference' => 'any',
        'base_price' => 100,
        'addons_total' => 0,
        'address_latitude' => 33.51,
        'address_longitude' => 36.31,
    ]);

    $listResponse = $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending');

    $listResponse->assertOk();

    $visibleBookingIds = collect($listResponse->json('data'))->pluck('id');

    expect($visibleBookingIds)->not->toContain($overlappingBooking->id)
        ->and($visibleBookingIds)->toContain($nonOverlappingBooking->id);

    $homepageResponse = $this->getJson('/api/v1/cleaning/worker/homepage');

    $homepageResponse->assertOk()
        ->assertJsonPath('newOrdersCount', 1);
});

it('uses estimated hours when total hours are unavailable while checking overlap', function (): void {
    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'trust_score' => 100,
        'is_active' => true,
        'is_suspended' => false,
        'home_address' => 'Worker Home',
        'home_latitude' => 33.50,
        'home_longitude' => 36.30,
    ]);

    CleaningWorkerDeposit::query()->create([
        'worker_id' => $worker->id,
        'current_balance' => 100000,
        'deposited_total' => 100000,
        'withdrawn_total' => 0,
        'minimum_required' => 0,
        'max_negative_balance' => 0,
    ]);

    Sanctum::actingAs($workerUser);

    CleaningBooking::factory()->create([
        'worker_id' => $worker->id,
        'status' => CleaningBookingStatus::WorkerAssigned,
        'scheduled_date' => '2026-07-20',
        'scheduled_time' => '09:00',
        'total_hours' => 0,
        'estimated_hours' => 3,
        'gender_preference' => 'any',
    ]);

    CleaningBooking::factory()->create([
        'worker_id' => null,
        'preferred_worker_id' => null,
        'status' => CleaningBookingStatus::Pending,
        'scheduled_date' => '2026-07-20',
        'scheduled_time' => '11:30',
        'total_hours' => 1,
        'estimated_hours' => 1,
        'gender_preference' => 'any',
        'base_price' => 100,
        'addons_total' => 0,
        'address_latitude' => 33.51,
        'address_longitude' => 36.31,
    ]);

    $response = $this->getJson('/api/v1/cleaning-bookings?filter[forCurrentWorker]=1&filter[status]=pending');

    $response->assertOk()
        ->assertJsonCount(0, 'data');
});
