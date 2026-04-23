<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

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

