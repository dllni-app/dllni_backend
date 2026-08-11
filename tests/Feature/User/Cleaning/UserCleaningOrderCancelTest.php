<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Events\CleaningBookingTrackingUpdated;
use Modules\Cleaning\Models\CleaningBooking;

use function Pest\Laravel\postJson;

it('allows cancelling pending cleaning orders', function (): void {
    Event::fake([CleaningBookingTrackingUpdated::class]);

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $order = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    postJson("/api/v1/user/cleaning/orders/{$order->id}/cancel", [
        'reason' => 'Changed plans',
    ])->assertOk()->assertJsonPath('order.status', CleaningBookingStatus::Cancelled->value);
});

it('allows cancelling worker assigned cleaning orders', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $order = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::WorkerAssigned->value,
    ]);

    postJson("/api/v1/user/cleaning/orders/{$order->id}/cancel", [
        'reason' => 'No longer needed',
    ])->assertOk()->assertJsonPath('order.status', CleaningBookingStatus::Cancelled->value);
});

it('allows cancelling cleaning orders during arrival start verification', function (CleaningBookingStatus $status): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $order = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => $status->value,
    ]);

    postJson("/api/v1/user/cleaning/orders/{$order->id}/cancel", [
        'reason' => 'Worker arrived but customer cancelled',
    ])->assertOk()
        ->assertJsonPath('order.status', CleaningBookingStatus::Cancelled->value)
        ->assertJsonPath('order.cancelledByRole', 'customer');

    expect($order->fresh())
        ->status->toBe(CleaningBookingStatus::Cancelled)
        ->cancellation_reason->toBe('Worker arrived but customer cancelled');
})->with([
    'awaiting start verification' => CleaningBookingStatus::AwaitingStartVerification,
    'awaiting worker start confirmation' => CleaningBookingStatus::AwaitingWorkerStartConfirmation,
]);

it('rejects cancelling cleaning orders in non cancellable statuses', function (CleaningBookingStatus $status): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $order = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => $status->value,
    ]);

    postJson("/api/v1/user/cleaning/orders/{$order->id}/cancel", [
        'reason' => 'Too late',
    ])->assertUnprocessable()->assertJsonValidationErrors(['order']);
})->with([
    'awaiting customer completion' => CleaningBookingStatus::AwaitingCustomerCompletion,
    'in progress' => CleaningBookingStatus::InProgress,
    'time extension requested' => CleaningBookingStatus::TimeExtensionRequested,
    'completed' => CleaningBookingStatus::Completed,
    'cancelled' => CleaningBookingStatus::Cancelled,
]);

it('prevents another user from cancelling someone else cleaning order', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    Sanctum::actingAs($otherUser);

    $order = CleaningBooking::factory()->create([
        'customer_id' => $owner->id,
        'status' => CleaningBookingStatus::Pending->value,
    ]);

    postJson("/api/v1/user/cleaning/orders/{$order->id}/cancel", [
        'reason' => 'Not mine',
    ])->assertNotFound();
});
