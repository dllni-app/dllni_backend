<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\User;
use App\Models\Worker;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

it('returns the current worker complete pricing before accepting the cleaning order', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 10,
            'commission_type' => 'percent',
            'commission_fixed_amount' => null,
            'travel_per_km' => 1000,
            'travel_distance_start_point' => 'worker_home',
        ],
    );

    $workerUser = User::factory()->create();
    $worker = Worker::factory()->create([
        'user_id' => $workerUser->id,
        'home_address' => 'Worker home',
        'home_latitude' => 33.5,
        'home_longitude' => 36.3,
    ]);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => User::factory()->create()->id,
        'worker_id' => null,
        'status' => CleaningBookingStatus::Pending,
        'number_of_workers' => 2,
        'base_price' => 10000,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'total_price' => 10000,
        'is_pricing_final' => false,
        'estimated_hours' => 4,
        'total_hours' => 4,
        'address_latitude' => 33.6,
        'address_longitude' => 36.3,
    ]);

    Sanctum::actingAs($workerUser);

    $response = $this->getJson("/api/v1/cleaning-bookings/{$booking->id}");

    $response->assertOk()
        ->assertJsonPath('data.basePrice', 5000)
        ->assertJsonPath('data.travelFee', 11500)
        ->assertJsonPath('data.adminMargin', 500)
        ->assertJsonPath('data.workerAmount', 16500)
        ->assertJsonPath('data.totalPrice', 17000)
        ->assertJsonPath('data.totalHours', 2)
        ->assertJsonPath('data.bookingTotalHours', 4)
        ->assertJsonPath('data.bookingBasePrice', 10000)
        ->assertJsonPath('data.myAssignment.workerId', $worker->id)
        ->assertJsonPath('data.myAssignment.serviceShareAmount', 5000)
        ->assertJsonPath('data.myAssignment.travelFee', 11500)
        ->assertJsonPath('data.myAssignment.adminMarginAmount', 500)
        ->assertJsonPath('data.myAssignment.workerAmount', 16500)
        ->assertJsonPath('data.myAssignment.totalHours', 2)
        ->assertJsonPath('data.myAssignment.isPreview', true);
});
