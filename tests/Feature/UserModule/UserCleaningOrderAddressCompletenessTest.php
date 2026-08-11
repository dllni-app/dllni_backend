<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\User\Models\UserAddress;

function cleaningAddressPayload(int $addressId, array $overrides = []): array
{
    return array_replace_recursive([
        'propertyType' => 'apartment',
        'propertyDetails' => [
            'address' => 'Aleppo - Test Street',
            'location_name' => 'Home',
            'bedrooms' => 1,
            'bathrooms' => 1,
            'kitchens' => 1,
            'living_room_size' => 'small',
            'cleaning_mode' => 'regular',
        ],
        'scheduledDate' => now()->addDay()->format('Y-m-d'),
        'scheduledTime' => '09:00',
        'addressId' => $addressId,
        'termsAccepted' => true,
    ], $overrides);
}

it('rejects cleaning orders when the selected address has no neighborhood', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $address = UserAddress::factory()->create([
        'user_id' => $user->id,
        'label' => 'Home',
        'city' => 'Aleppo',
        'neighborhood' => null,
        'neighborhood_id' => null,
        'street' => 'Test Street',
        'latitude' => 36.2021,
        'longitude' => 37.1343,
    ]);

    $this->postJson('/api/v1/user/cleaning/orders', cleaningAddressPayload((int) $address->id))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('addressId');
});

it('rejects event assistance orders when the selected address has no coordinates', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $address = UserAddress::factory()->create([
        'user_id' => $user->id,
        'label' => 'Home',
        'city' => 'Aleppo',
        'neighborhood' => 'الفرقان',
        'street' => 'Test Street',
        'latitude' => null,
        'longitude' => null,
    ]);

    $this->postJson('/api/v1/user/cleaning/orders', cleaningAddressPayload((int) $address->id, [
        'propertyType' => 'event_assistance',
        'propertyDetails' => [
            'address' => 'Aleppo - Al Furqan',
            'location_name' => 'Home',
            'eventType' => 'family_dinner',
            'guestCount' => 10,
            'venueType' => 'apartment',
            'customService' => 'تجهيز طاولة العشاء',
            'hours' => 3,
        ],
    ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('addressId');
});

it('rejects price estimates when the selected address is incomplete', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $address = UserAddress::factory()->create([
        'user_id' => $user->id,
        'label' => 'Home',
        'city' => 'Aleppo',
        'neighborhood' => 'الجميلية',
        'street' => 'Test Street',
        'latitude' => null,
        'longitude' => null,
    ]);

    $payload = cleaningAddressPayload((int) $address->id);
    unset($payload['scheduledDate'], $payload['scheduledTime'], $payload['termsAccepted']);

    $this->postJson('/api/v1/user/cleaning/orders/estimate-price', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('addressId');
});
