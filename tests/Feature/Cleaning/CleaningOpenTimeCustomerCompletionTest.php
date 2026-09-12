<?php

declare(strict_types=1);

use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

it('keeps finalized open-time billing stable when the customer confirms completion', function (): void {
    $startedAt = now()->subMinutes(61)->startOfSecond();
    $finishedAt = now()->startOfSecond();
    $booking = CleaningBooking::factory()->create([
        'status' => CleaningBookingStatus::InProgress,
        'booking_kind' => 'open_time',
        'number_of_workers' => 1,
        'open_time_hourly_rate' => 200,
        'open_time_minimum_minutes' => 60,
        'open_time_rounding_minutes' => 30,
        'work_started_at' => $startedAt,
        'addons_total' => 0,
        'travel_fee' => 0,
        'admin_margin_amount' => 0,
        'is_pricing_final' => false,
    ]);

    $booking->update([
        'status' => CleaningBookingStatus::AwaitingCustomerCompletion,
        'work_finished_at' => $finishedAt,
    ]);
    $booking->refresh();

    $finalizedAt = $booking->open_time_finalized_at;
    $finalAmount = (float) $booking->open_time_final_amount;
    $totalPrice = (float) $booking->total_price;

    $booking->update([
        'status' => CleaningBookingStatus::Completed,
        'customer_confirmed_at' => now(),
    ]);
    $booking->refresh();

    expect($booking->status)->toBe(CleaningBookingStatus::Completed)
        ->and($booking->open_time_finalized_at?->equalTo($finalizedAt))->toBeTrue()
        ->and((float) $booking->open_time_final_amount)->toBe($finalAmount)
        ->and((float) $booking->total_price)->toBe($totalPrice)
        ->and($booking->is_pricing_final)->toBeTrue();
});
