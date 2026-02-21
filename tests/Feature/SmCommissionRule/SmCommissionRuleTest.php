<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmCommissionRuleFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('lists commission rules', function (): void {
    SmCommissionRuleFactory::new()->count(3)->create();

    $response = $this->getJson('/api/v1/sm-commission-rules?perPage=10');

    $response->assertOk();
    expect($response->json('data'))->toHaveCount(3);
});

it('creates a commission rule', function (): void {
    $store = SmStoreFactory::new()->create();

    $payload = [
        'storeId' => $store->id,
        'commissionType' => 'percentage',
        'value' => 15,
        'isActive' => true,
    ];

    $response = $this->postJson('/api/v1/sm-commission-rules', $payload);

    $response->assertCreated();
    $this->assertDatabaseHas('sm_commission_rules', ['value' => 15]);
});

it('ensures only one default rule per store', function (): void {
    $store = SmStoreFactory::new()->create();

    // Create first default rule
    $rule1 = SmCommissionRuleFactory::new()->default()->create(['store_id' => $store->id]);
    expect($rule1->refresh()->is_default)->toBeTrue();

    // Create second default rule for same store
    $payload = [
        'storeId' => $store->id,
        'commissionType' => 'fixed',
        'value' => 5,
        'isDefault' => true,
    ];
    $this->postJson('/api/v1/sm-commission-rules', $payload);

    // First rule should no longer be default
    expect($rule1->refresh()->is_default)->toBeFalse();
});

it('deletes a commission rule', function (): void {
    $rule = SmCommissionRuleFactory::new()->create();

    $response = $this->deleteJson("/api/v1/sm-commission-rules/{$rule->id}");

    $response->assertNoContent();
    $this->assertDatabaseMissing('sm_commission_rules', ['id' => $rule->id]);
});
