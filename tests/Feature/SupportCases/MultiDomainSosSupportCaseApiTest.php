<?php

declare(strict_types=1);

use App\Enums\EmergencyType;
use App\Models\SupportCase;
use App\Models\SystemAlert;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Resturants\Models\Order;
use Modules\Supermarket\Models\SmOrder;

it('creates restaurant SOS through the unified support case flow', function (): void {
    $customer = User::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $customer->id,
        'status' => 'accepted',
    ]);

    Sanctum::actingAs($customer);

    $response = $this->postJson('/api/v1/support-cases', [
        'kind' => 'emergency',
        'bookingId' => $order->id,
        'bookingType' => 'restaurant_order',
        'emergencyType' => EmergencyType::SafetyThreat->value,
        'description' => 'I need urgent assistance with this restaurant order.',
        'clientRequestId' => 'restaurant-sos-1',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.kind', 'emergency')
        ->assertJsonPath('data.bookingType', 'restaurant_order')
        ->assertJsonPath('data.booking.type', 'restaurant_order')
        ->assertJsonPath('data.booking.id', $order->id)
        ->assertJsonPath('data.status', 'new');

    expect(SupportCase::query()->where('booking_type', 'restaurant_order')->where('booking_id', $order->id)->count())->toBe(1)
        ->and(SystemAlert::query()->where('booking_type', 'restaurant_order')->where('booking_id', $order->id)->count())->toBe(1);
});

it('keeps supermarket SOS bound to the supermarket order even when ids collide', function (): void {
    $customer = User::factory()->create();

    Order::factory()->create([
        'id' => 501,
        'user_id' => $customer->id,
        'status' => 'accepted',
    ]);

    $supermarketOrder = SmOrder::factory()->create([
        'id' => 501,
        'customer_id' => $customer->id,
        'status' => 'accepted',
    ]);

    Sanctum::actingAs($customer);

    $response = $this->postJson('/api/v1/support-cases', [
        'kind' => 'emergency',
        'bookingId' => 501,
        'bookingType' => 'supermarket_order',
        'emergencyType' => EmergencyType::MedicalEmergency->value,
        'description' => 'I need urgent assistance with this supermarket order.',
        'clientRequestId' => 'supermarket-sos-collision',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.bookingType', 'supermarket_order')
        ->assertJsonPath('data.booking.type', 'supermarket_order')
        ->assertJsonPath('data.booking.id', $supermarketOrder->id);

    $case = SupportCase::query()->latest('id')->firstOrFail();
    expect($case->booking_type)->toBe('supermarket_order')
        ->and($case->booking)->toBeInstanceOf(SmOrder::class);
});

it('creates delivery SOS for the owning user', function (): void {
    $customer = User::factory()->create();
    $deliveryOrder = DeliveryOrder::factory()->create([
        'created_by_user_id' => $customer->id,
        'status' => 'accepted',
    ]);

    Sanctum::actingAs($customer);

    $this->postJson('/api/v1/support-cases', [
        'kind' => 'emergency',
        'bookingId' => $deliveryOrder->id,
        'bookingType' => 'delivery_order',
        'emergencyType' => EmergencyType::SevereConflict->value,
        'description' => 'I need urgent assistance during this delivery.',
        'latitude' => 33.5138,
        'longitude' => 36.2765,
        'clientRequestId' => 'delivery-sos-1',
    ])->assertCreated()
        ->assertJsonPath('data.bookingType', 'delivery_order')
        ->assertJsonPath('data.booking.type', 'delivery_order')
        ->assertJsonPath('data.latitude', 33.5138)
        ->assertJsonPath('data.longitude', 36.2765);
});

it('prevents duplicate active SOS for the same reporter and order', function (): void {
    $customer = User::factory()->create();
    $order = Order::factory()->create([
        'user_id' => $customer->id,
        'status' => 'preparing',
    ]);

    Sanctum::actingAs($customer);

    $first = $this->postJson('/api/v1/support-cases', [
        'kind' => 'emergency',
        'bookingId' => $order->id,
        'bookingType' => 'restaurant_order',
        'emergencyType' => EmergencyType::SafetyThreat->value,
        'description' => 'First emergency request.',
        'clientRequestId' => 'restaurant-sos-a',
    ]);

    $second = $this->postJson('/api/v1/support-cases', [
        'kind' => 'emergency',
        'bookingId' => $order->id,
        'bookingType' => 'restaurant_order',
        'emergencyType' => EmergencyType::SafetyThreat->value,
        'description' => 'Retry with a different request id.',
        'clientRequestId' => 'restaurant-sos-b',
    ]);

    $first->assertCreated();
    $second->assertCreated()->assertJsonPath('data.id', $first->json('data.id'));

    expect(SupportCase::query()->where('booking_type', 'restaurant_order')->where('booking_id', $order->id)->count())->toBe(1);
});

it('rejects SOS for terminal orders and users that do not own the order', function (): void {
    $owner = User::factory()->create();
    $otherUser = User::factory()->create();
    $completedOrder = SmOrder::factory()->create([
        'customer_id' => $owner->id,
        'status' => 'completed',
    ]);

    Sanctum::actingAs($owner);

    $this->postJson('/api/v1/support-cases', [
        'kind' => 'emergency',
        'bookingId' => $completedOrder->id,
        'bookingType' => 'supermarket_order',
        'emergencyType' => EmergencyType::SafetyThreat->value,
        'description' => 'This should be rejected because the order is complete.',
    ])->assertUnprocessable();

    $activeOrder = SmOrder::factory()->create([
        'customer_id' => $owner->id,
        'status' => 'accepted',
    ]);

    Sanctum::actingAs($otherUser);

    $this->postJson('/api/v1/support-cases', [
        'kind' => 'emergency',
        'bookingId' => $activeOrder->id,
        'bookingType' => 'supermarket_order',
        'emergencyType' => EmergencyType::SafetyThreat->value,
        'description' => 'This user does not own the order.',
    ])->assertForbidden();
});
