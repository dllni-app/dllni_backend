<?php

declare(strict_types=1);

namespace Tests\RestaurantV2;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Modules\Resturants\Models\FoodDiscoveryCategory;
use Modules\User\Services\RestaurantV2SmartIntent;
use Tests\TestCase;

final class RestaurantV2SmartSearchContractTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        self::assertSame('local', app()->environment());
        self::assertSame('mysql', config('database.default'));
        self::assertContains(config('database.connections.mysql.host'), ['127.0.0.1', 'localhost']);
        self::assertSame('alnadha_worker_test', DB::connection()->getDatabaseName());
        config(['gemini.api_key' => 'test-only', 'gemini.retry_times' => 1]);
    }

    private function fakeGemini(array $payload): void
    {
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode($payload, JSON_UNESCAPED_UNICODE)]]]]]], 200)]);
    }

    public function test_smart_search_respects_verified_category_and_explicit_budget(): void
    {
        $this->fakeGemini(['goal' => 'find_food', 'searchText' => 'شاورما', 'maxPrice' => 100000]);
        $category = FoodDiscoveryCategory::query()->where('slug', 'shawarma')->firstOrFail();
        $response = $this->postJson('/api/v2/user/restaurants/search/smart', [
            'q' => 'بدي شاورما جاج تحت خمسين ألف', 'scope' => 'all', 'maxPrice' => 75, 'perPage' => 50,
        ])->assertOk();
        $response->assertJsonPath('interpretation.foodCategoryId', (int) $category->id);
        self::assertEquals(75, $response->json('interpretation.maxPrice'));
        self::assertSame('دجاج', $response->json('interpretation.requiredAttribute'));
        $rows = $response->json('data.dishes.items');
        self::assertNotEmpty($rows);
        foreach ($rows as $row) {
            self::assertSame((int) $category->id, $row['foodCategory']['id']);
            self::assertLessThanOrEqual(75, (float) $row['price']);
            self::assertTrue($row['isIndependentlyOrderable']);
        }
        Http::assertSentCount(1);
    }

    public function test_servings_and_reviewed_ingredient_exclusion_are_hard_filters(): void
    {
        $this->fakeGemini(['goal' => 'find_food', 'searchText' => 'شاورما', 'excludedAttributes' => ['بصل']]);
        $category = FoodDiscoveryCategory::query()->where('slug', 'shawarma')->firstOrFail();
        $products = \Modules\Resturants\Models\Product::query()->where('primary_food_category_id', $category->id)
            ->where('classification_status', 'reviewed')->where('is_available', true)->where('catalog_role','independent')->take(3)->get();
        self::assertCount(3, $products);
        $products[0]->update(['servings'=> 2, 'ingredient_exclusions'=>['بصل']]);
        $products[1]->update(['servings'=> 1, 'ingredient_exclusions'=>['بصل']]);
        $products[2]->update(['servings'=> 3, 'ingredient_exclusions'=>null]);
        $response = $this->postJson('/api/v2/user/restaurants/search/smart', [
            'q' => 'شاورما لشخصين بدون بصل', 'scope'=>'dishes', 'perPage'=>50,
        ])->assertOk();
        $ids = array_column($response->json('data'), 'id');
        self::assertContains($products[0]->id, $ids);
        self::assertNotContains($products[1]->id, $ids);
        self::assertNotContains($products[2]->id, $ids);
        $response->assertJsonPath('interpretation.servings', 2);
    }

    public function test_meal_index_uses_existing_upsert_contract(): void
    {
        $product = \Modules\Resturants\Models\Product::query()->where('catalog_role','independent')->whereHas('restaurant', fn ($q) => $q->where('is_active', true))->firstOrFail();
        config(['services.dallelni_search.meal_index_enabled'=>true, 'services.dallelni_search.restaurant_products_base_url'=>'https://index.test', 'services.dallelni_search.auth_token'=>'test-only']);
        Http::fake(['https://index.test/*'=>Http::response(['action'=>'updated', 'product_id'=>$product->id])]);
        (new \Modules\Resturants\Jobs\SyncRestaurantMealIndex($product->id))->handle();
        Http::assertSent(fn ($request) => $request->url() === 'https://index.test/meals/upsert' && $request['product_id'] === $product->id);
    }

    public function test_budget_parsing_and_required_query(): void
    {
        $intent = app(RestaurantV2SmartIntent::class);
        self::assertEquals(50000, $intent->budget('تحت خمسين الف'));
        self::assertEquals(50000, $intent->budget('تحت 50,000'));
        self::assertEquals(1500, $intent->budget('تحت 1.5 الف'));
        $this->postJson('/api/v2/user/restaurants/search/smart', ['scope' => 'all'])->assertUnprocessable();
    }

    public function test_voice_upload_transcribes_and_rejects_invalid_audio(): void
    {
        $this->fakeGemini(['transcript' => 'بدي شاورما دجاج']);
        $pcm = str_repeat("\0", 32000);
        $wav = 'RIFF'.pack('V', 36 + strlen($pcm)).'WAVEfmt '.pack('VvvVVvv', 16, 1, 1, 16000, 32000, 2, 16).'data'.pack('V', strlen($pcm)).$pcm;
        $this->post('/api/v2/user/restaurants/search/voice', [
            'audio' => UploadedFile::fake()->createWithContent('voice.wav', $wav),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.transcript', 'بدي شاورما دجاج');
        $this->post('/api/v2/user/restaurants/search/voice', [
            'audio' => UploadedFile::fake()->createWithContent('bad.wav', 'invalid'),
        ], ['Accept' => 'application/json'])->assertUnprocessable();
        Http::assertSentCount(1);
    }
}
