<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

describe('Store Owner Product Show', function (): void {
    it('returns a product owned by the authenticated seller', function (): void {
        $seller = User::factory()->create([
            'module_type' => UserModuleType::SupermarketSeller->value,
        ]);
        Sanctum::actingAs($seller);

        $store = SmStoreFactory::new()->create(['owner_user_id' => $seller->id]);
        $product = SmProductFactory::new()->create([
            'store_id' => $store->id,
            'name' => 'Owner Product',
        ]);

        $response = $this->getJson("/api/v1/store-owner/products/{$product->id}");

        $response->assertSuccessful()
            ->assertJsonPath('data.id', $product->id)
            ->assertJsonPath('data.storeId', $store->id)
            ->assertJsonPath('data.name', 'Owner Product');
    });

    it('forbids viewing a product that belongs to another seller store', function (): void {
        $seller = User::factory()->create([
            'module_type' => UserModuleType::SupermarketSeller->value,
        ]);
        Sanctum::actingAs($seller);

        $otherSeller = User::factory()->create([
            'module_type' => UserModuleType::SupermarketSeller->value,
        ]);
        $otherStore = SmStoreFactory::new()->create(['owner_user_id' => $otherSeller->id]);
        $product = SmProductFactory::new()->create(['store_id' => $otherStore->id]);

        $response = $this->getJson("/api/v1/store-owner/products/{$product->id}");

        $response->assertForbidden();
    });
});

describe('Store Owner Product Delete', function (): void {
    it('deletes a product owned by the authenticated seller', function (): void {
        $seller = User::factory()->create([
            'module_type' => UserModuleType::SupermarketSeller->value,
        ]);
        Sanctum::actingAs($seller);

        $store = SmStoreFactory::new()->create(['owner_user_id' => $seller->id]);
        $product = SmProductFactory::new()->create(['store_id' => $store->id]);

        $response = $this->deleteJson("/api/v1/store-owner/products/{$product->id}");

        $response->assertNoContent();

        assertDatabaseMissing('sm_products', ['id' => $product->id]);
    });

    it('forbids deleting a product that belongs to another seller store', function (): void {
        $seller = User::factory()->create([
            'module_type' => UserModuleType::SupermarketSeller->value,
        ]);
        Sanctum::actingAs($seller);

        $otherSeller = User::factory()->create([
            'module_type' => UserModuleType::SupermarketSeller->value,
        ]);
        $otherStore = SmStoreFactory::new()->create(['owner_user_id' => $otherSeller->id]);
        $product = SmProductFactory::new()->create(['store_id' => $otherStore->id]);

        $response = $this->deleteJson("/api/v1/store-owner/products/{$product->id}");

        $response->assertForbidden();
    });
});



describe('Store Owner Product Options', function (): void {
    it('updates product option groups and returns their active state', function (): void {
        $seller = User::factory()->create([
            'module_type' => UserModuleType::SupermarketSeller->value,
        ]);
        Sanctum::actingAs($seller);

        $store = SmStoreFactory::new()->create(['owner_user_id' => $seller->id]);
        $product = SmProductFactory::new()->create(['store_id' => $store->id]);

        $response = $this->putJson("/api/v1/store-owner/products/{$product->id}/options", [
            'options' => [[
                'name' => 'الحجم',
                'isRequired' => true,
                'minSelections' => 1,
                'maxSelections' => 1,
                'sortOrder' => 0,
                'isActive' => false,
                'modifiers' => [[
                    'name' => 'كبير',
                    'price' => 500,
                    'sortOrder' => 0,
                    'isAvailable' => true,
                ]],
            ]],
        ]);

        $response->assertSuccessful()
            ->assertJsonPath('data.options.0.name', 'الحجم')
            ->assertJsonPath('data.options.0.isRequired', true)
            ->assertJsonPath('data.options.0.isActive', false)
            ->assertJsonPath('data.options.0.modifiers.0.name', 'كبير')
            ->assertJsonPath('data.options.0.modifiers.0.price', 500);

        assertDatabaseHas('sm_modifier_groups', [
            'store_id' => $store->id,
            'name' => 'الحجم',
            'is_required' => 1,
            'is_active' => 0,
        ]);
        assertDatabaseHas('sm_modifiers', [
            'name' => 'كبير',
            'price' => 500,
            'is_available' => 1,
        ]);
    });

    it('forbids updating options for a product from another seller store', function (): void {
        $seller = User::factory()->create([
            'module_type' => UserModuleType::SupermarketSeller->value,
        ]);
        Sanctum::actingAs($seller);
        SmStoreFactory::new()->create(['owner_user_id' => $seller->id]);

        $otherSeller = User::factory()->create([
            'module_type' => UserModuleType::SupermarketSeller->value,
        ]);
        $otherStore = SmStoreFactory::new()->create(['owner_user_id' => $otherSeller->id]);
        $product = SmProductFactory::new()->create(['store_id' => $otherStore->id]);

        $this->putJson("/api/v1/store-owner/products/{$product->id}/options", [
            'options' => [],
        ])->assertForbidden();
    });
});

describe('Store Owner Product Available Count', function (): void {
    it('counts only available products in the authenticated owner store', function (): void {
        $seller = User::factory()->create([
            'module_type' => UserModuleType::SupermarketSeller->value,
        ]);
        Sanctum::actingAs($seller);

        $store = SmStoreFactory::new()->create(['owner_user_id' => $seller->id]);
        SmProductFactory::new()->count(2)->create([
            'store_id' => $store->id,
            'is_available' => true,
        ]);
        SmProductFactory::new()->create([
            'store_id' => $store->id,
            'is_available' => false,
        ]);

        $otherSeller = User::factory()->create([
            'module_type' => UserModuleType::SupermarketSeller->value,
        ]);
        $otherStore = SmStoreFactory::new()->create(['owner_user_id' => $otherSeller->id]);
        SmProductFactory::new()->count(3)->create([
            'store_id' => $otherStore->id,
            'is_available' => true,
        ]);

        $this->getJson('/api/v1/sm-products/available-count')
            ->assertSuccessful()
            ->assertJsonPath('count', 2);
    });
});
