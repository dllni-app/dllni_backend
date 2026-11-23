<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Cleaning\Models\CleaningBooking;

test('booking details retain exact V3 identity, V2 envelope and legacy V1 fallback', function (): void {
    $customer = User::factory()->create(['is_active' => true]);
    Sanctum::actingAs($customer);
    $booking = CleaningBooking::factory()->create(['customer_id' => $customer->id]);

    $booking->forceFill(['contract_version' => 'v3'])->save();
    $this->getJson("/api/v1/user/cleaning/orders/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.contractVersion', 3);

    $booking->forceFill([
        'contract_version' => 'v2',
        'property_details' => [
            '_time_period_v2' => [
                'rooms' => [],
                'appointments' => [],
            ],
        ],
    ])->save();
    $this->getJson("/api/v1/user/cleaning/orders/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.contractVersion', 2);

    $booking->forceFill(['contract_version' => null, 'property_details' => []])->save();
    $this->getJson("/api/v1/user/cleaning/orders/{$booking->id}")
        ->assertOk()
        ->assertJsonPath('data.contractVersion', 1);
});
