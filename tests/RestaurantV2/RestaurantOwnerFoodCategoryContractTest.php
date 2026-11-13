<?php

declare(strict_types=1);

namespace Tests\RestaurantV2;

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\FoodDiscoveryCategory;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Tests\TestCase;

final class RestaurantOwnerFoodCategoryContractTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('local', app()->environment());
        self::assertSame('mysql', config('database.default'));
        self::assertContains(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost']);
        self::assertSame('alnadha_worker_test', DB::connection()->getDatabaseName());
    }

    public function test_guest_cannot_read_owner_food_categories(): void
    {
        $this->getJson('/api/v1/restaurant-owner/food-categories')->assertUnauthorized();
    }

    public function test_authorized_owner_can_get_distinct_global_food_categories(): void
    {
        $owner = User::factory()->create([
            'module_type' => UserModuleType::RestaurantSeller->value,
            'email' => 'food-owner-'.Str::uuid().'@example.test',
        ]);
        Restaurant::factory()->create(['user_id' => $owner->id]);
        Sanctum::actingAs($owner);

        $result = $this->getJson('/api/v1/restaurant-owner/food-categories')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['data' => [['id', 'slug', 'name']]]);

        self::assertContains('burger', array_column($result->json('data'), 'slug'));
        self::assertNotEmpty($result->json('data'));
    }

    public function test_owner_edit_invalidates_existing_editorial_approval_without_changing_product_ownership(): void
    {
        $owner = User::factory()->create([
            'module_type' => UserModuleType::RestaurantSeller->value,
            'email' => 'food-edit-'.Str::uuid().'@example.test',
        ]);
        $restaurant = Restaurant::factory()->create(['user_id' => $owner->id]);
        $category = Category::factory()->create(['restaurant_id' => $restaurant->id]);
        $foodCategory = FoodDiscoveryCategory::query()->where('slug', 'burger')->firstOrFail();
        $product = Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'category_id' => $category->id,
            'product_kind' => 'main_dish',
            'primary_food_category_id' => $foodCategory->id,
            'catalog_role' => 'independent',
            'classification_status' => 'reviewed',
            'classified_at' => now(),
        ]);
        Sanctum::actingAs($owner);

        $response = $this->putJson('/api/v1/restaurant-owner/products/'.$product->id, [
            'categoryId' => $category->id,
            'name' => $product->name,
            'price' => (float) $product->price,
            'productKind' => 'side_dish',
            'primaryFoodCategoryId' => $foodCategory->id,
        ]);
        $response->assertOk()
            ->assertJsonPath('data.productKind', 'side_dish')
            ->assertJsonPath('data.classificationStatus', 'unreviewed');
        self::assertSame('unreviewed', $product->fresh()->classification_status);
        self::assertNull($product->fresh()->classified_at);
        self::assertSame($restaurant->id, $product->fresh()->restaurant_id);
    }

    public function test_non_restaurant_seller_cannot_read_owner_food_categories(): void
    {
        $other = User::factory()->create([
            'module_type' => UserModuleType::CleaningWorker->value,
            'email' => 'food-other-'.Str::uuid().'@example.test',
        ]);
        Sanctum::actingAs($other);

        $this->getJson('/api/v1/restaurant-owner/food-categories')->assertForbidden();
    }
}
