<?php

declare(strict_types=1);

use App\Models\MasterProduct;
use App\Models\User;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmSmartListFactory;
use Database\Factories\SmStoreFactory;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Jobs\ProcessSmartListScheduleJob;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmSmartListItem;
use Modules\Supermarket\Models\SmSmartListSchedule;
use Modules\Supermarket\Notifications\SmartListScheduledOrderSentNotification;

it('updates a smart list with schedule payload', function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);

    $store = SmStoreFactory::new()->create();
    $smartList = SmSmartListFactory::new()->create([
        'user_id' => $user->id,
    ]);

    $payload = [
        'storeId' => $store->id,
        'schedule' => [
            'frequencyType' => 'weekly',
            'dayOfWeek' => 6,
            'isActive' => true,
        ],
    ];

    $response = $this->putJson("/api/v1/sm-smart-lists/{$smartList->id}", $payload);

    $response->assertOk();
    $this->assertDatabaseHas('sm_smart_lists', [
        'id' => $smartList->id,
        'store_id' => $store->id,
    ]);
    $this->assertDatabaseHas('sm_smart_list_schedules', [
        'smart_list_id' => $smartList->id,
        'frequency_type' => 'weekly',
        'day_of_week' => 6,
        'is_active' => true,
    ]);
});

it('creates scheduled order and sends arabic notification', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $store = SmStoreFactory::new()->create();
    $category = SmCategoryFactory::new()->create();
    $masterProduct = MasterProduct::factory()->create();

    $smartList = SmSmartListFactory::new()->create([
        'user_id' => $user->id,
        'store_id' => $store->id,
        'is_active' => true,
    ]);

    SmSmartListItem::query()->create([
        'smart_list_id' => $smartList->id,
        'master_product_id' => $masterProduct->id,
        'quantity' => 2.4,
        'unit' => 'piece',
        'sort_order' => 1,
        'is_included' => true,
    ]);

    $product = SmProduct::query()->create([
        'store_id' => $store->id,
        'category_id' => $category->id,
        'master_product_id' => $masterProduct->id,
        'name' => 'Test Product',
        'source_type' => 'manual',
        'price' => 15,
        'discounted_price' => null,
        'stock_quantity' => 100,
        'low_stock_threshold' => 5,
        'is_available' => true,
    ]);

    $schedule = SmSmartListSchedule::query()->create([
        'smart_list_id' => $smartList->id,
        'frequency_type' => 'weekly',
        'day_of_week' => now()->dayOfWeek,
        'is_active' => true,
        'next_run_at' => now()->subMinute(),
    ]);

    (new ProcessSmartListScheduleJob($schedule->id))->handle();

    $this->assertDatabaseCount('sm_orders', 1);
    $order = \Modules\Supermarket\Models\SmOrder::query()->firstOrFail();

    $this->assertDatabaseHas('sm_order_items', [
        'order_id' => $order->id,
        'product_id' => $product->id,
        'quantity' => 2,
    ]);

    Notification::assertSentTo(
        $user,
        SmartListScheduledOrderSentNotification::class
    );
});
