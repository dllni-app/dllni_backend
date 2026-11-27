<?php

declare(strict_types=1);

namespace Tests\RestaurantV2;

use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Data\CategoryData;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\FoodDiscoveryCategory;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Modules\Resturants\Services\CategoryService;
use Modules\Resturants\Services\ProductClassificationRules;
use Modules\User\Services\RestaurantFoodEligibility;
use Modules\User\Services\RestaurantGroupVoteService;
use Modules\User\Services\RestaurantProductTypeResolver;
use Tests\TestCase;

final class RestaurantTaxonomyV2ContractTest extends TestCase
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

    public function test_seeded_catalog_has_valid_explicit_classification_and_real_photos(): void
    {
        $products = RestaurantFoodEligibility::browsableProducts(Product::query())
            ->with(['foodCategory', 'media'])->get();
        self::assertGreaterThanOrEqual(184, $products->count());
        foreach ($products as $product) {
            self::assertSame('reviewed', $product->classification_status, $product->name);
            ProductClassificationRules::validate($product->attributesToArray(), true);
            $photo = $product->getFirstMedia('primary-image');
            self::assertNotNull($photo, $product->name);
            self::assertFileExists($photo->getPath());
            $size = getimagesize($photo->getPath());
            self::assertGreaterThanOrEqual(300, $size[0], $product->name);
            self::assertGreaterThanOrEqual(200, $size[1], $product->name);
        }
        self::assertSame(15, FoodDiscoveryCategory::query()->where('is_active', true)->count());
    }

    public function test_family_and_presentation_filters_are_intersected_and_hierarchy_is_ordered(): void
    {
        $shawarma = FoodDiscoveryCategory::query()->where('slug', 'shawarma')->firstOrFail();
        $merchant = Restaurant::query()->where('slug', 'shawarma-al-hara')->firstOrFail();
        $url = '/api/v2/user/restaurants/search?scope=dishes&foodCategoryId='.$shawarma->id.'&itemTypes[]=sandwich&perPage=50';
        $rows = $this->getJson($url)->assertOk()->json('data');
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertSame('sandwich', $row['itemType']);
            self::assertSame('shawarma', $row['foodCategory']['slug']);
        }
        $sections = $this->getJson('/api/v2/user/restaurants/'.$merchant->id.'/menu')->assertOk()->json('data.categories');
        if ($sections === null) $sections = $this->getJson('/api/v2/user/restaurants/'.$merchant->id.'/menu')->assertOk()->json('categories');
        self::assertNotEmpty($sections);
        $root = Category::query()->where('restaurant_id', $merchant->id)->where('slug', 'shawarma')->firstOrFail();
        $children = collect($sections)->where('parentId', $root->id)->values();
        self::assertCount(3, $children);
        $rootPayload = collect($sections)->firstWhere('id', $root->id);
        self::assertSame(0, $rootPayload['totalProducts']);
        self::assertSame(8, $rootPayload['subtreeProducts']);
        $root->update(['is_active' => false]);
        $hidden = $this->getJson($url)->assertOk()->json('data');
        self::assertNotContains($merchant->id, array_column($hidden, 'restaurantId'));
    }

    public function test_section_service_rejects_cycles_cross_tenant_and_nonempty_deletion(): void
    {
        $merchant = Restaurant::query()->where('slug', 'shawarma-al-hara')->firstOrFail();
        $root = Category::query()->where('restaurant_id', $merchant->id)->where('slug', 'shawarma')->firstOrFail();
        $child = $root->children()->firstOrFail();
        $foreign = Category::query()->where('restaurant_id', '!=', $merchant->id)->whereNull('parent_id')->firstOrFail();
        $service = app(CategoryService::class);
        $dto = CategoryData::from(['restaurantId' => $merchant->id, 'name' => $root->name, 'slug' => $root->slug, 'sortOrder' => $root->sort_order]);
        foreach ([$root->id, $child->id, $foreign->id] as $parentId) {
            try {
                $service->update($dto, $root, ['parentId' => $parentId]);
                self::fail('An invalid hierarchy was accepted.');
            } catch (ValidationException $exception) {
                self::assertArrayHasKey('parentId', $exception->errors());
            }
        }
        try {
            $service->delete($root);
            self::fail('A section containing children was deleted.');
        } catch (ValidationException $exception) {
            self::assertArrayHasKey('category', $exception->errors());
        }
    }

    public function test_luck_box_uses_verified_portions_and_budget_and_filters_vote_suggestions(): void
    {
        $user = User::query()->where('email', 'restaurant.customer@dllni.sy')->firstOrFail();
        Sanctum::actingAs($user);
        $family = FoodDiscoveryCategory::query()->where('slug', 'shawarma')->firstOrFail();
        $merchant = Restaurant::query()->where('slug', 'shawarma-al-hara')->firstOrFail();
        $payload = ['groupSize' => 3, 'budgetPerPerson' => 500, 'foodCategoryId' => $family->id, 'mode' => 'sandwiches', 'restaurantId' => $merchant->id];
        $result = $this->postJson('/api/v1/user/restaurants/luck-box/suggest', $payload)->assertOk()->json();
        self::assertNotEmpty($result['bundles']);
        foreach ($result['bundles'] as $bundle) {
            self::assertGreaterThanOrEqual(3, $bundle['coverage']);
            self::assertLessThanOrEqual(1500, $bundle['totalPrice']);
            self::assertSame($merchant->id, $bundle['restaurant']['id']);
            self::assertSame('food_subtotal', $bundle['priceBasis']);
            $coverage = 0;
            foreach ($bundle['lineItems'] as $line) {
                self::assertSame('sandwich', $line['itemType']);
                self::assertSame($family->id, $line['foodCategoryId']);
                self::assertNotEmpty($line['imageUrl']);
                $coverage += $line['quantity'] * $line['servings'];
                self::assertLessThanOrEqual(Product::findOrFail($line['productId'])->stock_quantity, $line['quantity']);
            }
            self::assertSame($bundle['coverage'], $coverage);
        }
        $this->postJson('/api/v1/user/restaurants/luck-box/suggest', $payload + ['restrictions' => ['nut_free']])
            ->assertOk()->assertJsonPath('noMatchReason', 'unverified_restrictions')->assertJsonCount(0, 'bundles');
        $vote = app(RestaurantGroupVoteService::class)->suggestionsCatalog(null, null, 50, $family->id, ['sandwich']);
        self::assertNotEmpty($vote['suggestions']);
        foreach ($vote['suggestions'] as $row) {
            self::assertSame('sandwich', $row['itemType']);
            self::assertSame($family->id, $row['foodCategoryId']);
        }
    }

    public function test_legacy_type_fallback_never_uses_meal_ingredients_as_presentation(): void
    {
        $resolver = new RestaurantProductTypeResolver();
        self::assertSame('meal', $resolver->resolve(null, 'ساندويشات', 'وجبة شاورما', 'ساندويش مع بطاطا'));
        self::assertSame('sandwich', $resolver->resolve('sandwich', 'وجبات', 'وجبة', ''));
    }
}
