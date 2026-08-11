<?php

declare(strict_types=1);

use App\Models\CleaningFinancialSetting;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

it('returns the configured user cancellation fee', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 5,
            'vat_rate' => 0,
            'travel_markup_type' => 'fixed',
            'travel_markup_value' => 0,
            'user_cancellation_fee' => 15000.50,
        ],
    );

    Sanctum::actingAs(User::factory()->create());

    getJson('/api/v1/user/cleaning/cancellation-fee')
        ->assertOk()
        ->assertJsonPath('amount', 15000.5)
        ->assertJsonStructure(['amount', 'currency']);
});

it('stores the configured cancellation fee when a user cancels an order', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 5,
            'vat_rate' => 0,
            'travel_markup_type' => 'fixed',
            'travel_markup_value' => 0,
            'user_cancellation_fee' => 22000,
        ],
    );

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $order = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::Pending->value,
        'cancellation_fee' => 0,
    ]);

    postJson("/api/v1/user/cleaning/orders/{$order->id}/cancel", [
        'reason' => 'Changed plans',
    ])
        ->assertOk()
        ->assertJsonPath('order.cancellationFee', 22000);

    expect((float) $order->fresh()->cancellation_fee)->toBe(22000.0)
        ->and($order->fresh()->cancelled_by_role)->toBe('customer');
});

it('stores the cancellation fee when cancelling during arrival verification', function (): void {
    CleaningFinancialSetting::query()->updateOrCreate(
        ['id' => 1],
        [
            'default_commission_rate' => 5,
            'vat_rate' => 0,
            'travel_markup_type' => 'fixed',
            'travel_markup_value' => 0,
            'user_cancellation_fee' => 9000,
        ],
    );

    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $order = CleaningBooking::factory()->create([
        'customer_id' => $user->id,
        'status' => CleaningBookingStatus::AwaitingStartVerification->value,
        'cancellation_fee' => 0,
    ]);

    postJson("/api/v1/user/cleaning/orders/{$order->id}/cancel", [
        'reason' => 'Cannot receive worker',
    ])->assertOk();

    expect((float) $order->fresh()->cancellation_fee)->toBe(9000.0);
});
