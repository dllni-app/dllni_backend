<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\User\Models\UserAddress;

it('lists addresses with default first', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $second = UserAddress::factory()->for($user)->create([
        'label' => 'العمل',
        'city' => 'Aleppo',
        'is_default' => false,
    ]);
    $first = UserAddress::factory()->for($user)->create([
        'label' => 'المنزل',
        'city' => 'Aleppo',
        'is_default' => true,
    ]);

    $response = $this->getJson('/api/v1/user/addresses');

    $response->assertOk();
    expect($response->json('addresses.0.id'))->toBe($first->id);
    expect($response->json('addresses.1.id'))->toBe($second->id);
});

it('creates an address and marks first one as default', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $response = $this->postJson('/api/v1/user/addresses', [
        'label' => 'المنزل',
        'city' => 'حلب',
        'neighborhood' => 'الفرقان',
        'street' => 'شارع الجامعة',
    ]);

    $response->assertCreated();
    expect($response->json('address.isDefault'))->toBeTrue();
    $this->assertDatabaseHas('user_addresses', [
        'user_id' => $user->id,
        'label' => 'المنزل',
        'is_default' => true,
    ]);
});

it('rejects create without any location detail', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $response = $this->postJson('/api/v1/user/addresses', [
        'label' => 'المنزل',
    ]);

    $response->assertUnprocessable();
});

it('updates an address', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $address = UserAddress::factory()->for($user)->create([
        'label' => 'Old',
        'city' => 'Damascus',
        'is_default' => true,
    ]);

    $response = $this->putJson("/api/v1/user/addresses/{$address->id}", [
        'label' => 'New',
        'city' => 'Damascus',
        'directions' => 'by the pharmacy',
        'isDefault' => true,
    ]);

    $response->assertOk();
    expect($response->json('address.label'))->toBe('New');
    expect($response->json('address.directions'))->toBe('by the pharmacy');
});

it('deletes an address and promotes another default when needed', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $defaultAddr = UserAddress::factory()->for($user)->create([
        'label' => 'A',
        'city' => 'X',
        'is_default' => true,
    ]);
    $other = UserAddress::factory()->for($user)->create([
        'label' => 'B',
        'city' => 'Y',
        'is_default' => false,
    ]);

    $this->deleteJson("/api/v1/user/addresses/{$defaultAddr->id}")->assertNoContent();

    expect(UserAddress::query()->find($other->id)?->is_default)->toBeTrue();
});

it('sets default via patch', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $a = UserAddress::factory()->for($user)->create(['label' => 'A', 'city' => 'C1', 'is_default' => true]);
    $b = UserAddress::factory()->for($user)->create(['label' => 'B', 'city' => 'C2', 'is_default' => false]);

    $response = $this->patchJson("/api/v1/user/addresses/{$b->id}/set-default");

    $response->assertOk();
    expect($response->json('address.isDefault'))->toBeTrue();
    expect(UserAddress::query()->find($a->id)?->is_default)->toBeFalse();
    expect(UserAddress::query()->find($b->id)?->is_default)->toBeTrue();
});

it('returns not found for another users address', function (): void {
    $owner = User::factory()->create();
    $intruder = User::factory()->create();
    $address = UserAddress::factory()->for($owner)->create(['label' => 'X', 'city' => 'Y']);

    Sanctum::actingAs($intruder);

    $this->putJson("/api/v1/user/addresses/{$address->id}", [
        'label' => 'Hacked',
        'city' => 'Z',
    ])->assertNotFound();

    $this->deleteJson("/api/v1/user/addresses/{$address->id}")->assertNotFound();
});

it('requires authentication', function (): void {
    $this->getJson('/api/v1/user/addresses')->assertUnauthorized();
});
