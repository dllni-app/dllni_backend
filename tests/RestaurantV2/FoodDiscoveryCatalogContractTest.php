<?php

declare(strict_types=1);

namespace Tests\RestaurantV2;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Resturants\Models\FoodDiscoveryCategory;
use Modules\Resturants\Models\Product;
use Modules\User\Services\RestaurantFoodEligibility;
use Modules\User\Services\UserRestaurantCartService;
use Tests\TestCase;

final class FoodDiscoveryCatalogContractTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();

        self::assertSame('local', app()->environment());
        self::assertSame('mysql', config('database.default'));
        self::assertContains(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost']);
        self::assertSame('alnadha_worker_test', DB::connection()->getDatabaseName());
        self::assertSame('alnadha_worker_test', DB::selectOne('SELECT DATABASE() AS db')->db);
    }

    public function test_guest_food_categories_and_popular_meal_sections_are_consistent(): void
    {
        $this->getJson('/api/v2/user/restaurants/food-categories')
            ->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonStructure(['data']);
        $this->getJson('/api/v2/user/restaurants/home/food-categories')
            ->assertOk()->assertJsonStructure(['data' => ['items']]);
        $this->getJson('/api/v2/user/restaurants/home/popular-meals')
            ->assertOk()->assertJsonStructure(['data' => ['items', 'meta']]);
        $this->getJson('/api/v2/user/restaurants/home')
            ->assertOk()->assertJsonStructure(['data' => ['sections' => [
                'promotions', 'cuisines', 'restaurants', 'dishes', 'foodCategories', 'popularMeals',
            ]]]);
    }

    public function test_explicit_modifier_only_product_cannot_leak_into_catalog_or_new_cart(): void
    {
        $product = Product::query()
            ->whereHas('restaurant', fn ($restaurant) => $restaurant->where('is_active', true))
            ->where('is_available', true)
            ->firstOrFail();
        $product->forceFill(['catalog_role' => 'modifier_only'])->save();

        self::assertFalse(RestaurantFoodEligibility::isIndependentlyOrderable($product->fresh()));
        $this->getJson('/api/v2/user/restaurants/products/'.$product->id)->assertNotFound();

        $q = rawurlencode(mb_substr((string) $product->name, 0, 100));
        $searched = $this->getJson('/api/v2/user/restaurants/search?scope=dishes&q='.$q.'&perPage=50')->assertOk();
        self::assertNotContains((int) $product->id, array_map(
            static fn (array $row): int => (int) $row['id'],
            $searched->json('data'),
        ));

        try {
            app(UserRestaurantCartService::class)->addItem(999999, (int) $product->id, 1);
            self::fail('An independently ineligible modifier must not be added to the cart.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('productId', $exception->errors());
        }
    }

    public function test_arabic_spelling_variants_match_without_changing_stored_product_names(): void
    {
        $original = Product::query()->where('name', 'آيس كريم')->where('is_available', true)->firstOrFail();
        $results = $this->getJson('/api/v2/user/restaurants/search?scope=dishes&q='.
            rawurlencode('ايس كريم').'&perPage=50')->assertOk()->json('data');

        self::assertContains((int) $original->id, array_map(
            static fn (array $row): int => (int) $row['id'],
            $results,
        ));
        self::assertSame('آيس كريم', $original->fresh()->name);
    }

    public function test_reviewed_meal_and_global_category_are_distinct_from_local_menu_category(): void
    {
        $foodCategory = FoodDiscoveryCategory::query()->where('slug', 'burger')->firstOrFail();
        $product = Product::query()
            ->whereHas('restaurant', fn ($restaurant) => $restaurant->where('is_active', true))
            ->where('is_available', true)
            ->firstOrFail();
        $product->forceFill([
            'catalog_role' => 'independent',
            'classification_status' => 'reviewed',
            'product_kind' => 'main_dish',
            'primary_food_category_id' => $foodCategory->id,
        ])->save();

        $categories = $this->getJson('/api/v2/user/restaurants/food-categories')->assertOk()->json('data');
        self::assertContains((int) $foodCategory->id, array_map(
            static fn (array $row): int => (int) $row['id'],
            $categories,
        ));

        $this->getJson('/api/v2/user/restaurants/discovery?foodCategoryId='.$foodCategory->id)
            ->assertOk()->assertJsonStructure(['data', 'meta']);
        $this->getJson('/api/v2/user/restaurants/search?scope=dishes&foodCategoryId='.$foodCategory->id.'&q='.
            rawurlencode(mb_substr((string) $product->name, 0, 100)))
            ->assertOk();
        $this->getJson('/api/v2/user/restaurants/discovery?foodCategoryId=999999999')
            ->assertUnprocessable()->assertJsonValidationErrors(['foodCategoryId']);
    }
}
