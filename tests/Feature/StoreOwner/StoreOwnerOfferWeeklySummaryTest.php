<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Carbon\Carbon;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmOfferFactory;
use Database\Factories\SmOfferProductFactory;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmOrderItemFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\getJson;

afterEach(function (): void {
    Carbon::setTestNow();
});

it('returns weekly offers summary with active scheduled used and ended counts', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 3, 18, 12, 0, 0));

    $owner = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);
    Sanctum::actingAs($owner);

    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $owner->id,
    ]);

    $customer = User::factory()->create();

    $weekStart = now()->startOfWeek(Carbon::SATURDAY);
    $weekEnd = $weekStart->copy()->addDays(6)->endOfDay();

    $category = SmCategoryFactory::new()->create([
        'store_id' => $store->id,
    ]);

    $productA = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
    ]);

    $productB = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
    ]);

    $productC = SmProductFactory::new()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
    ]);

    $alwaysActiveOffer = SmOfferFactory::new()->create([
        'store_id' => $store->id,
        'starts_at' => $weekStart->copy()->subDay(),
        'ends_at' => $weekEnd->copy()->addWeek(),
        'is_active' => true,
    ]);

    $startsOnMondayOffer = SmOfferFactory::new()->create([
        'store_id' => $store->id,
        'starts_at' => $weekStart->copy()->addDays(2)->setTime(9, 0),
        'ends_at' => $weekEnd->copy()->addWeek(),
        'is_active' => true,
    ]);

    SmOfferFactory::new()->create([
        'store_id' => $store->id,
        'starts_at' => $weekEnd->copy()->addDay(),
        'ends_at' => $weekEnd->copy()->addDays(10),
        'is_active' => true,
    ]);

    SmOfferFactory::new()->create([
        'store_id' => $store->id,
        'starts_at' => $weekStart->copy()->subDays(5),
        'ends_at' => $weekStart->copy()->addDay()->setTime(18, 0),
        'is_active' => true,
    ]);

    SmOfferProductFactory::new()->create([
        'offer_id' => $alwaysActiveOffer->id,
        'product_id' => $productA->id,
    ]);

    SmOfferProductFactory::new()->create([
        'offer_id' => $startsOnMondayOffer->id,
        'product_id' => $productB->id,
    ]);

    $saturdayOrder = SmOrderFactory::new()->create([
        'customer_id' => $customer->id,
        'store_id' => $store->id,
        'created_at' => $weekStart->copy()->setTime(10, 0),
        'updated_at' => $weekStart->copy()->setTime(10, 0),
    ]);

    SmOrderItemFactory::new()->create([
        'order_id' => $saturdayOrder->id,
        'product_id' => $productA->id,
    ]);

    // Duplicate item in the same order should still count as one distinct order.
    SmOrderItemFactory::new()->create([
        'order_id' => $saturdayOrder->id,
        'product_id' => $productA->id,
    ]);

    $mondayOrder = SmOrderFactory::new()->create([
        'customer_id' => $customer->id,
        'store_id' => $store->id,
        'created_at' => $weekStart->copy()->addDays(2)->setTime(10, 0),
        'updated_at' => $weekStart->copy()->addDays(2)->setTime(10, 0),
    ]);

    SmOrderItemFactory::new()->create([
        'order_id' => $mondayOrder->id,
        'product_id' => $productB->id,
    ]);

    $tuesdayOrder = SmOrderFactory::new()->create([
        'customer_id' => $customer->id,
        'store_id' => $store->id,
        'created_at' => $weekStart->copy()->addDays(3)->setTime(12, 0),
        'updated_at' => $weekStart->copy()->addDays(3)->setTime(12, 0),
    ]);

    SmOrderItemFactory::new()->create([
        'order_id' => $tuesdayOrder->id,
        'product_id' => $productC->id,
    ]);

    $response = getJson('/api/v1/store-owner/offers/weekly-summary');

    $response->assertOk();

    $response->assertJsonPath('message', 'Weekly offers analytics retrieved successfully.');
    $response->assertJsonPath('data.week.startDate', '2026-03-14');
    $response->assertJsonPath('data.week.endDate', '2026-03-20');
    $response->assertJsonPath('data.week.weekStartsOn', 'saturday');

    $response->assertJsonPath('data.series.0.day', 'saturday');
    $response->assertJsonPath('data.series.0.activeOffers', 2);
    $response->assertJsonPath('data.series.0.scheduledOffers', 2);
    $response->assertJsonPath('data.series.0.ordersUsedOffers', 1);

    $response->assertJsonPath('data.series.2.day', 'monday');
    $response->assertJsonPath('data.series.2.activeOffers', 2);
    $response->assertJsonPath('data.series.2.scheduledOffers', 1);
    $response->assertJsonPath('data.series.2.ordersUsedOffers', 1);

    $response->assertJsonPath('data.totals.activeOffers', 14);
    $response->assertJsonPath('data.totals.scheduledOffers', 9);
    $response->assertJsonPath('data.totals.ordersUsedOffers', 2);
    $response->assertJsonPath('data.totals.endedOffers', 1);
});

it('returns forbidden when seller has no store', function (): void {
    Carbon::setTestNow(Carbon::create(2026, 3, 18, 12, 0, 0));

    $owner = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller->value,
    ]);
    Sanctum::actingAs($owner);

    $response = getJson('/api/v1/store-owner/offers/weekly-summary');

    $response->assertForbidden();
});
