<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;

it('exposes an editable flag for a non-terminal cleaning booking', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    getJson("/api/v1/user/cleaning/orders/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.canEdit', true)
        ->assertJsonPath('data.can_edit', true);
});

it('returns the editable flag from the cleaning booking update response', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    patchJson("/api/v1/user/cleaning/orders/{$booking->id}", [
        'scheduledDate' => now()->addDays(2)->format('Y-m-d'),
        'scheduledTime' => '11:30',
    ])
        ->assertOk()
        ->assertJsonPath('order.canEdit', true)
        ->assertJsonPath('order.can_edit', true)
        ->assertJsonPath('order.scheduledTime', '11:30');
});

it('disables editing for terminal cleaning booking statuses', function (CleaningBookingStatus $status): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $booking = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => $status->value,
    ]);

    getJson("/api/v1/user/cleaning/orders/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.canEdit', false)
        ->assertJsonPath('data.can_edit', false);
})->with([
    'in progress' => CleaningBookingStatus::InProgress,
    'completed' => CleaningBookingStatus::Completed,
    'cancelled' => CleaningBookingStatus::Cancelled,
]);
