<?php

declare(strict_types=1);

use App\Enums\MasterProductUnit;
use App\Models\MasterProduct;
use App\Models\Recipe;
use App\Models\RecipeAlias;
use App\Models\RecipeIngredient;
use App\Models\User;
use Database\Factories\CategoryFactory;
use Database\Factories\ProductFactory;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Restaurant;

beforeEach(function (): void {
    config()->set('gemini.base_url', 'https://gemini.test');
    config()->set('gemini.api_key', 'test-key');
    config()->set('gemini.text_model', 'test-model');
    config()->set('gemini.timeout', 2);
    config()->set('gemini.retry_times', 0);

    config()->set('services.dallelni_search.auth_token', 'test-token');
    config()->set('services.dallelni_search.restaurant_products_base_url', 'https://ai.test/meals');
    config()->set('services.dallelni_search.products_base_url', 'https://ai.test/products');
    config()->set('services.dallelni_search.stores_base_url', 'https://ai.test/sm-stores');
});

it('keeps a requested restaurant meal separate from sandwich alternatives and rewards fast preparation', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $restaurant = Restaurant::factory()->create([
        'name' => 'Fast Chicken',
        'estimated_preparation_time_min' => 8,
        'estimated_preparation_time_max' => 15,
        'estimated_preparation_time' => 15,
        'average_rating' => 4.5,
    ]);
    $category = CategoryFactory::new()->create(['restaurant_id' => $restaurant->id]);

    $meal = ProductFactory::new()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'name' => 'وجبة دجاج كريسبي حارة',
        'item_type' => 'meal',
        'search_tags' => ['كريسبي', 'حار', 'دجاج'],
        'preparation_time' => 10,
        'price' => 30000,
    ]);

    $sandwich = ProductFactory::new()->create([
        'restaurant_id' => $restaurant->id,
        'category_id' => $category->id,
        'name' => 'سندويشة كريسبي حارة',
        'item_type' => 'sandwich',
        'search_tags' => ['كريسبي', 'حار', 'دجاج'],
        'preparation_time' => 7,
        'price' => 20000,
    ]);

    Http::fake(function (Request $request) use ($meal, $sandwich) {
        if (str_contains($request->url(), 'gemini.test')) {
            $intent = [
                'goal' => 'find_food',
                'searchText' => 'دجاج كريسبي حار',
                'itemType' => 'meal',
                'concepts' => ['دجاج', 'كريسبي'],
                'attributes' => ['حار'],
                'excludedAttributes' => [],
                'excludedItemTypes' => [],
                'restaurantName' => '',
                'cuisine' => '',
                'maxPrice' => 0,
                'maxPreparationMinutes' => 0,
                'minimumRating' => 0,
                'fastPreparation' => true,
                'lowPrice' => false,
                'highRating' => false,
                'nearby' => false,
                'confidence' => 0.98,
            ];

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode($intent, JSON_UNESCAPED_UNICODE)]]],
                ]],
            ]);
        }

        if ($request->url() === 'https://ai.test/meals/search') {
            return Http::response([
                'query' => 'دجاج كريسبي حار',
                'results' => [
                    ['product_id' => (string) $sandwich->id, 'score' => 0.99],
                    ['product_id' => (string) $meal->id, 'score' => 0.94],
                ],
            ]);
        }

        return Http::response([], 404);
    });

    $response = $this->postJson('/api/v1/user/smart-search', [
        'section' => 'restaurant',
        'query' => 'بدي وجبة كريسبي حارة تخلص بسرعة',
        'locale' => 'ar',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.interpretation.itemType', 'meal')
        ->assertJsonPath('data.results.mode', 'food')
        ->assertJsonPath('data.results.bestMatches.0.id', $meal->id)
        ->assertJsonPath('data.results.similarAlternatives.0.id', $sandwich->id);
});

it('expands a recipe into products and prioritizes the requested supermarket without making it strict', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $preferredStore = SmStoreFactory::new()->create([
        'name' => 'سوبرماركت الأطرش',
        'average_rating' => 4.2,
    ]);
    $otherStore = SmStoreFactory::new()->create([
        'name' => 'سوبرماركت النور',
        'average_rating' => 4.8,
    ]);
    $preferredCategory = SmCategoryFactory::new()->create(['store_id' => $preferredStore->id]);
    $otherCategory = SmCategoryFactory::new()->create(['store_id' => $otherStore->id]);

    $master = MasterProduct::query()->create([
        'name' => 'معكرونة لازانيا',
        'unit' => MasterProductUnit::Gram->value,
        'package_quantity' => 500,
        'package_unit' => 'g',
        'sell_mode' => 'package',
        'brand' => 'Test',
        'description' => 'شرائح لازانيا',
        'is_active' => true,
    ]);

    $recipe = Recipe::query()->create([
        'name' => 'لازانيا باللحمة',
        'slug' => 'lasagna-test',
        'description' => 'لازانيا',
        'servings' => 4,
        'is_active' => true,
    ]);
    RecipeAlias::query()->create([
        'recipe_id' => $recipe->id,
        'alias' => 'لازانيا',
    ]);
    RecipeIngredient::query()->create([
        'recipe_id' => $recipe->id,
        'master_product_id' => $master->id,
        'quantity' => 500,
        'unit' => MasterProductUnit::Gram->value,
        'is_optional' => false,
    ]);

    $preferredProduct = SmProductFactory::new()->create([
        'store_id' => $preferredStore->id,
        'category_id' => $preferredCategory->id,
        'master_product_id' => $master->id,
        'name' => 'معكرونة لازانيا 500 غ',
        'stock_quantity' => 10,
        'is_available' => true,
    ]);
    $otherProduct = SmProductFactory::new()->create([
        'store_id' => $otherStore->id,
        'category_id' => $otherCategory->id,
        'master_product_id' => $master->id,
        'name' => 'شرائح لازانيا 500 غ',
        'stock_quantity' => 10,
        'is_available' => true,
    ]);

    Http::fake(function (Request $request) use ($preferredProduct, $otherProduct, $preferredStore) {
        if (str_contains($request->url(), 'gemini.test')) {
            $intent = [
                'goal' => 'prepare_recipe',
                'searchText' => 'لازانيا',
                'preferredStoreName' => 'الأطرش',
                'storeStrict' => false,
                'sameStoreRequired' => false,
                'recipeName' => 'لازانيا',
                'contextName' => '',
                'servings' => 4,
                'alreadyHave' => [],
                'excludedIngredients' => [],
                'items' => [],
                'inferredIngredients' => [],
                'confidence' => 0.99,
            ];

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode($intent, JSON_UNESCAPED_UNICODE)]]],
                ]],
            ]);
        }

        if ($request->url() === 'https://ai.test/products/search/batch') {
            $queries = $request->data()['queries'] ?? [];
            $results = [];

            foreach ($queries as $query) {
                $key = (string) ($query['key'] ?? '');
                $storeId = $query['store_id'] ?? null;
                $rows = [];

                if ($storeId !== null && (int) $storeId === (int) $preferredStore->id) {
                    $rows[] = ['product_id' => (string) $preferredProduct->id, 'score' => 0.98];
                } elseif ($storeId === null) {
                    $rows[] = ['product_id' => (string) $otherProduct->id, 'score' => 0.99];
                    $rows[] = ['product_id' => (string) $preferredProduct->id, 'score' => 0.96];
                }

                $results[] = [
                    'key' => $key,
                    'query' => (string) ($query['query'] ?? ''),
                    'results' => $rows,
                ];
            }

            return Http::response(['results' => $results]);
        }

        return Http::response([], 404);
    });

    $response = $this->postJson('/api/v1/user/smart-search', [
        'section' => 'supermarket',
        'query' => 'بدي حضر لازانيا من عند سوبرماركت الأطرش',
        'locale' => 'ar',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.interpretation.goal', 'prepare_recipe')
        ->assertJsonPath('data.results.recipe.canonicalName', 'لازانيا باللحمة')
        ->assertJsonPath('data.results.preferredStore.store.id', $preferredStore->id)
        ->assertJsonPath('data.results.preferredStore.coverage', 1)
        ->assertJsonPath('data.results.storeStrict', false)
        ->assertJsonPath('data.results.items.0.query', 'معكرونة لازانيا');
});

it('keeps a preferred supermarket first for a multi-item basket and calculates package quantities', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $preferredStore = SmStoreFactory::new()->create([
        'name' => 'سوبرماركت الأطرش',
        'average_rating' => 4.0,
    ]);
    $otherStore = SmStoreFactory::new()->create([
        'name' => 'سوبرماركت السلطان',
        'average_rating' => 4.8,
    ]);
    $preferredCategory = SmCategoryFactory::new()->create(['store_id' => $preferredStore->id]);
    $otherCategory = SmCategoryFactory::new()->create(['store_id' => $otherStore->id]);

    $preferredRice = SmProductFactory::new()->create([
        'store_id' => $preferredStore->id,
        'category_id' => $preferredCategory->id,
        'name' => 'رز بسمتي 5 كغ',
        'stock_quantity' => 30,
        'is_available' => true,
    ]);
    $preferredLentils = SmProductFactory::new()->create([
        'store_id' => $preferredStore->id,
        'category_id' => $preferredCategory->id,
        'name' => 'عدس أحمر 1 كغ',
        'stock_quantity' => 30,
        'is_available' => true,
    ]);
    $otherRice = SmProductFactory::new()->create([
        'store_id' => $otherStore->id,
        'category_id' => $otherCategory->id,
        'name' => 'رز فاخر 10 كغ',
        'stock_quantity' => 30,
        'is_available' => true,
    ]);
    $otherLentils = SmProductFactory::new()->create([
        'store_id' => $otherStore->id,
        'category_id' => $otherCategory->id,
        'name' => 'عدس 1 كغ',
        'stock_quantity' => 30,
        'is_available' => true,
    ]);

    Http::fake(function (Request $request) use (
        $preferredStore,
        $preferredRice,
        $preferredLentils,
        $otherRice,
        $otherLentils,
    ) {
        if (str_contains($request->url(), 'gemini.test')) {
            $intent = [
                'goal' => 'multi_product_search',
                'searchText' => 'عدس ورز',
                'preferredStoreName' => 'الأطرش',
                'storeStrict' => false,
                'sameStoreRequired' => false,
                'recipeName' => '',
                'contextName' => '',
                'servings' => 0,
                'alreadyHave' => [],
                'excludedIngredients' => [],
                'items' => [
                    ['query' => 'عدس', 'quantity' => 1, 'unit' => 'kg'],
                    ['query' => 'رز', 'quantity' => 10, 'unit' => 'kg'],
                ],
                'inferredIngredients' => [],
                'confidence' => 0.99,
            ];

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode($intent, JSON_UNESCAPED_UNICODE)]]],
                ]],
            ]);
        }

        if ($request->url() === 'https://ai.test/products/search/batch') {
            $rows = [];
            foreach (($request->data()['queries'] ?? []) as $query) {
                $key = (string) ($query['key'] ?? '');
                $isRice = str_contains((string) ($query['query'] ?? ''), 'رز');
                $storeId = $query['store_id'] ?? null;

                if ($storeId !== null && (int) $storeId === (int) $preferredStore->id) {
                    $results = [[
                        'product_id' => (string) ($isRice ? $preferredRice->id : $preferredLentils->id),
                        'score' => 0.93,
                    ]];
                } else {
                    $results = [
                        [
                            'product_id' => (string) ($isRice ? $otherRice->id : $otherLentils->id),
                            'score' => 0.99,
                        ],
                        [
                            'product_id' => (string) ($isRice ? $preferredRice->id : $preferredLentils->id),
                            'score' => 0.91,
                        ],
                    ];
                }

                $rows[] = [
                    'key' => $key,
                    'query' => (string) ($query['query'] ?? ''),
                    'results' => $results,
                ];
            }

            return Http::response(['results' => $rows]);
        }

        return Http::response([], 404);
    });

    $response = $this->postJson('/api/v1/user/smart-search', [
        'section' => 'supermarket',
        'query' => 'بدي عدس كيلو ورز عشرة كيلو من عند سوبرماركت الأطرش',
        'locale' => 'ar',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.results.preferredStore.store.id', $preferredStore->id)
        ->assertJsonPath('data.results.preferredStore.coverage', 1)
        ->assertJsonPath('data.results.preferredStore.items.1.product.packagePlan.packages', 2)
        ->assertJsonPath('data.results.preferredStore.items.1.product.packagePlan.exact', true)
        ->assertJsonPath('data.results.alternativeStores.0.store.id', $otherStore->id);
});

it('supports a supermarket store-search intent directly', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStoreFactory::new()->create([
        'name' => 'سوبرماركت الأطرش',
        'average_rating' => 4.4,
    ]);

    Http::fake(function (Request $request) {
        if (str_contains($request->url(), 'gemini.test')) {
            $intent = [
                'goal' => 'find_store',
                'searchText' => 'سوبرماركت الأطرش',
                'preferredStoreName' => 'الأطرش',
                'storeStrict' => false,
                'sameStoreRequired' => false,
                'recipeName' => '',
                'contextName' => '',
                'servings' => 0,
                'alreadyHave' => [],
                'excludedIngredients' => [],
                'items' => [],
                'inferredIngredients' => [],
                'confidence' => 0.99,
            ];

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode($intent, JSON_UNESCAPED_UNICODE)]]],
                ]],
            ]);
        }

        return Http::response([], 404);
    });

    $response = $this->postJson('/api/v1/user/smart-search', [
        'section' => 'supermarket',
        'query' => 'دورلي سوبرماركت الأطرش',
        'locale' => 'ar',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.results.mode', 'store')
        ->assertJsonPath('data.results.stores.0.id', $store->id);
});

it('does not let a preferred supermarket override product relevance', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $preferredStore = SmStoreFactory::new()->create([
        'name' => 'سوبرماركت الأطرش',
        'average_rating' => 4.3,
    ]);
    $otherStore = SmStoreFactory::new()->create([
        'name' => 'سوبرماركت النور',
        'average_rating' => 4.6,
    ]);
    $preferredCategory = SmCategoryFactory::new()->create(['store_id' => $preferredStore->id]);
    $otherCategory = SmCategoryFactory::new()->create(['store_id' => $otherStore->id]);

    $wrongMaster = MasterProduct::query()->create([
        'name' => 'عدس أحمر',
        'unit' => MasterProductUnit::Kilogram->value,
        'is_active' => true,
    ]);

    $unrelated = SmProductFactory::new()->create([
        'store_id' => $preferredStore->id,
        'category_id' => $preferredCategory->id,
        'master_product_id' => $wrongMaster->id,
        'name' => 'كرواسون زبدة',
        'stock_quantity' => 20,
        'is_available' => true,
    ]);

    $correct = SmProductFactory::new()->create([
        'store_id' => $otherStore->id,
        'category_id' => $otherCategory->id,
        'name' => 'عدس أحمر 1 كغ',
        'stock_quantity' => 20,
        'is_available' => true,
    ]);

    Http::fake(function (Request $request) use ($preferredStore, $unrelated, $correct) {
        if (str_contains($request->url(), 'gemini.test')) {
            $intent = [
                'goal' => 'multi_product_search',
                'searchText' => 'عدس',
                'preferredStoreName' => 'الأطرش',
                'storeStrict' => false,
                'sameStoreRequired' => false,
                'recipeName' => '',
                'contextName' => '',
                'servings' => 0,
                'alreadyHave' => [],
                'excludedIngredients' => [],
                'items' => [
                    ['query' => 'عدس', 'quantity' => 1, 'unit' => 'kg'],
                ],
                'inferredIngredients' => [],
                'confidence' => 0.99,
            ];

            return Http::response([
                'candidates' => [[
                    'content' => ['parts' => [['text' => json_encode($intent, JSON_UNESCAPED_UNICODE)]]],
                ]],
            ]);
        }

        if ($request->url() === 'https://ai.test/products/search/batch') {
            $rows = [];
            foreach (($request->data()['queries'] ?? []) as $query) {
                $key = (string) ($query['key'] ?? '');
                $storeId = $query['store_id'] ?? null;

                $results = $storeId !== null && (int) $storeId === (int) $preferredStore->id
                    ? [['product_id' => (string) $unrelated->id, 'score' => 0.99]]
                    : [
                        ['product_id' => (string) $correct->id, 'score' => 0.82],
                        ['product_id' => (string) $unrelated->id, 'score' => 0.99],
                    ];

                $rows[] = [
                    'key' => $key,
                    'query' => (string) ($query['query'] ?? ''),
                    'results' => $results,
                ];
            }

            return Http::response(['results' => $rows]);
        }

        return Http::response([], 404);
    });

    $response = $this->postJson('/api/v1/user/smart-search', [
        'section' => 'supermarket',
        'query' => 'بدي عدس كيلو من عند سوبرماركت الأطرش',
        'locale' => 'ar',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.results.preferredStore.store.id', $preferredStore->id)
        ->assertJsonPath('data.results.preferredStore.coverage', 0)
        ->assertJsonPath('data.results.alternativeStores.0.store.id', $otherStore->id)
        ->assertJsonPath('data.results.alternativeStores.0.items.0.product.id', $correct->id);
});
