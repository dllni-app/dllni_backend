<?php

declare(strict_types=1);

namespace Tests\RestaurantV2;

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Category;
use Modules\Resturants\Models\Product;
use Modules\Resturants\Models\Restaurant;
use Tests\TestCase;

final class RestaurantOwnerProductComplementsTest extends TestCase
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

    public function test_owner_selection_is_ordered_and_shared_by_both_customer_apis(): void
    {
        [$owner, $restaurant] = $this->merchant();
        $meal = $this->product($restaurant, 'main_dish');
        $drink = $this->product($restaurant, 'beverage');
        $side = $this->product($restaurant, 'side_dish');
        $sauce = $this->product($restaurant, 'standalone_sauce', ['classification_status' => 'unreviewed']);
        $dessert = $this->product($restaurant, 'dessert');
        $combo = $this->product($restaurant, 'combo_meal');
        $url = '/api/v1/restaurant-owner/products/'.$meal->id.'/complements';

        $this->getJson($url)->assertUnauthorized();
        Sanctum::actingAs($owner);
        $this->getJson($url)->assertOk()->assertJsonPath('data.mode', 'automatic')
            ->assertJsonPath('data.supported', true)->assertJsonCount(4, 'data.candidates');
        $this->putJson($url, ['mode' => 'custom', 'productIds' => [$sauce->id, $drink->id, $side->id]])
            ->assertOk()->assertJsonPath('data.productIds', [$sauce->id, $drink->id, $side->id]);

        $this->assertCustomerIds($meal, [$sauce->id, $drink->id, $side->id]);
        $drink->update(['stock_quantity' => 0]);
        $side->update(['is_available' => false]);
        $this->assertCustomerIds($meal, [$sauce->id]);
        $side->update(['unavailable_until' => now()->subMinute()]);
        $this->assertCustomerIds($meal, [$sauce->id, $side->id]);
        $this->getJson('/api/v1/user/products/'.$side->id)->assertOk();
        $side->update(['unavailable_until' => null]);
        $sauce->update(['product_kind' => 'main_dish']);
        $this->assertCustomerIds($meal, []);
        $this->putJson($url, ['mode' => 'disabled', 'productIds' => []])->assertOk();
        $this->assertCustomerIds($meal, []);
        $this->putJson($url, ['mode' => 'automatic', 'productIds' => []])->assertOk();
        $this->assertCustomerIds($meal, [$dessert->id]);
        self::assertNull($meal->fresh()->complementary_product_ids);

        $this->assertCustomerIds($combo, [$dessert->id]);
        $drink->update(['stock_quantity' => 10]);
        $this->putJson('/api/v1/restaurant-owner/products/'.$combo->id.'/complements', [
            'mode' => 'custom', 'productIds' => [$drink->id],
        ])->assertOk();
        $this->assertCustomerIds($combo, [$drink->id]);
        $this->assertCustomerIds($drink, []);
    }

    public function test_invalid_or_foreign_links_are_rejected_without_overwriting_saved_selection(): void
    {
        [$owner, $restaurant] = $this->merchant();
        [, $otherRestaurant] = $this->merchant();
        $meal = $this->product($restaurant, 'main_dish');
        $drink = $this->product($restaurant, 'beverage');
        $foreign = $this->product($otherRestaurant, 'beverage');
        $ingredient = $this->product($restaurant, 'standalone_sauce', ['catalog_role' => 'modifier_only']);
        $otherMeal = $this->product($restaurant, 'main_dish');
        Sanctum::actingAs($owner);
        $url = '/api/v1/restaurant-owner/products/'.$meal->id.'/complements';
        $this->putJson($url, ['mode' => 'custom', 'productIds' => [$drink->id]])->assertOk();
        foreach ([[$foreign->id], [$meal->id], [$ingredient->id], [$otherMeal->id], [$drink->id, $drink->id], [], array_fill(0, 7, $drink->id)] as $ids) {
            $this->putJson($url, ['mode' => 'custom', 'productIds' => $ids])->assertUnprocessable();
        }
        $this->putJson($url, ['mode' => 'automatic', 'productIds' => [$drink->id]])->assertUnprocessable();
        $this->getJson('/api/v1/restaurant-owner/products/'.$foreign->id.'/complements')->assertForbidden();
        $this->putJson('/api/v1/restaurant-owner/products/'.$foreign->id.'/complements', [
            'mode' => 'disabled', 'productIds' => [],
        ])->assertForbidden();
        $this->putJson('/api/v1/restaurant-owner/products/'.$drink->id.'/complements', [
            'mode' => 'disabled', 'productIds' => [],
        ])->assertUnprocessable();
        self::assertSame([$drink->id], $meal->fresh()->complementary_product_ids);

        // Editing ordinary product details must not overwrite a separately saved configuration.
        $this->putJson('/api/v1/restaurant-owner/products/'.$meal->id, [
            'categoryId' => $meal->category_id,
            'name' => 'Updated meal',
            'price' => (float) $meal->price,
            'productKind' => 'main_dish',
        ])->assertOk();
        self::assertSame([$drink->id], $meal->fresh()->complementary_product_ids);

        // Deleting the selected item must never leak an invalid product or substitute automatic picks.
        $drink->delete();
        $this->assertCustomerIds($meal, []);
        $this->getJson($url)->assertOk()->assertJsonPath('data.mode', 'custom');
    }

    private function assertCustomerIds(Product $product, array $expected): void
    {
        $v1 = $this->getJson('/api/v1/user/products/'.$product->id)->assertOk();
        $v2 = $this->getJson('/api/v2/user/restaurants/products/'.$product->id)->assertOk();
        self::assertSame($expected, array_column($v1->json('complementaryProducts'), 'id'));
        self::assertSame($expected, array_column($v2->json('data.complementaryProducts'), 'id'));
    }

    private function merchant(): array
    {
        $owner = User::factory()->create(['module_type' => UserModuleType::RestaurantSeller->value]);
        return [$owner, Restaurant::factory()->create(['user_id' => $owner->id, 'is_active' => true])];
    }

    private function product(Restaurant $restaurant, string $kind, array $overrides = []): Product
    {
        return Product::factory()->create([
            'restaurant_id' => $restaurant->id,
            'category_id' => Category::factory()->create(['restaurant_id' => $restaurant->id])->id,
            'product_kind' => $kind,
            'classification_status' => 'reviewed',
            'catalog_role' => 'independent',
            'is_available' => true,
            'stock_quantity' => 10,
            ...$overrides,
        ]);
    }
}
