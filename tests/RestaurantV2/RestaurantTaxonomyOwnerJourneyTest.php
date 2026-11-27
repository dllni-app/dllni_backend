<?php

declare(strict_types=1);

namespace Tests\RestaurantV2;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Tests\TestCase;

final class RestaurantTaxonomyOwnerJourneyTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('local', app()->environment());
        self::assertSame('mysql', DB::connection()->getDriverName());
        self::assertContains(DB::connection()->getConfig('host'), ['127.0.0.1', 'localhost']);
        self::assertSame('alnadha_worker_test', DB::connection()->getDatabaseName());
    }

    public function test_seeded_owner_can_edit_reorder_and_hide_sections_seen_by_customer(): void
    {
        $restaurant = Restaurant::query()->where('email', 'seller@restaurant.dllni.sy')->firstOrFail();
        $owner = $restaurant->user;
        Sanctum::actingAs($owner);
        $roots = Category::query()->where('restaurant_id', $restaurant->id)->whereNull('parent_id')
            ->orderBy('sort_order')->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $this->getJson('/api/v1/categories?perPage=100')->assertOk();
        $rootId = $this->postJson('/api/v1/categories', ['name' => 'QA root', 'slug' => 'qa-root-'.uniqid(), 'sortOrder' => 9999])
            ->assertCreated()->json('data.id');
        $childId = $this->postJson('/api/v1/categories', ['name' => 'QA child', 'slug' => 'qa-child-'.uniqid(), 'sortOrder' => 0, 'parentId' => $rootId])
            ->assertCreated()->json('data.id');
        $root = Category::findOrFail($rootId);
        $this->putJson('/api/v1/categories/reorder', [
            'parentId' => null,
            'expectedIds' => [...$roots, $rootId],
            'orderedIds' => [$rootId, ...$roots],
        ])->assertNoContent();
        self::assertSame(0, $root->fresh()->sort_order);
        $product = Product::query()->where('restaurant_id', $restaurant->id)->where('is_available', true)
            ->where('catalog_role', 'independent')->firstOrFail();
        $originalCategoryId = $product->category_id;
        // Move the fixture only within this transaction; the original catalog is restored on rollback.
        $product->update(['category_id' => $childId]);
        $url = '/api/v2/user/restaurants/'.$restaurant->id.'/menu';
        $payload = $this->getJson($url)->assertOk()->json('data.categories');
        self::assertContains($childId, array_column($payload, 'id'));
        $this->putJson('/api/v1/categories/'.$rootId, [
            'name' => $root->name, 'slug' => $root->slug, 'sortOrder' => 0, 'isActive' => false,
        ])->assertOk();
        $payload = $this->getJson($url)->assertOk()->json('data.categories');
        self::assertNotContains($rootId, array_column($payload, 'id'));
        self::assertNotContains($childId, array_column($payload, 'id'));
        $this->deleteJson('/api/v1/categories/'.$rootId)->assertUnprocessable();
        $foreign = Category::query()->where('restaurant_id', '!=', $restaurant->id)->firstOrFail();
        $this->getJson('/api/v1/categories/'.$foreign->id)->assertNotFound();
        $product->update(['category_id' => $originalCategoryId]);
        $this->deleteJson('/api/v1/categories/'.$childId)->assertNoContent();
        $this->deleteJson('/api/v1/categories/'.$rootId)->assertNoContent();
    }
}
