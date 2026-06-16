<?php

declare(strict_types=1);

use Database\Factories\MasterProductFactory;
use App\Models\User;
use Database\Factories\SmCategoryFactory;
use Database\Factories\SmSmartListFactory;
use Database\Factories\SmStoreFactory;
use Illuminate\Support\Facades\Notification;
use Modules\Supermarket\Data\SmSmartListData;
use Modules\Supermarket\Jobs\ProcessSmartListScheduleJob;
use Modules\Supermarket\Models\SmProduct;
use Modules\Supermarket\Models\SmSmartListItem;
use Modules\Supermarket\Models\SmSmartListSchedule;
use Modules\Supermarket\Notifications\SmartListScheduledOrderSentNotification;
use Modules\Supermarket\Services\SmSmartListService;

it('updates a smart list with schedule payload', function (): void {
    $user = User::factory()->create();
    $store = SmStoreFactory::new()->create();
    $smartList = SmSmartListFactory::new()->create([
        'user_id' => $user->id,
    ]);

    $payload = [
        'storeId' => $store->id,
        'schedule' => [
            'frequencyType' => 'weekly',
            'weekDays' => [6],
            'isActive' => true,
            'periods' => [
                [
                    'label' => 'الفترة الأولى',
                    'fromTime' => '09:00',
                    'toTime' => '11:00',
                ],
            ],
        ],
    ];

    $service = app(SmSmartListService::class);
    $service->update(SmSmartListData::from($payload), $smartList);

    $this->assertDatabaseHas('sm_smart_lists', [
        'id' => $smartList->id,
        'store_id' => $store->id,
    ]);

    $schedule = SmSmartListSchedule::query()->where('smart_list_id', $smartList->id)->firstOrFail();

    expect($schedule->frequency_type)->toBe('weekly')
        ->and($schedule->week_days)->toBe([6])
        ->and($schedule->periods)->toBe([
            [
                'label' => 'الفترة الأولى',
                'fromTime' => '09:00',
                'toTime' => '11:00',
            ],
        ])
        ->and($schedule->is_active)->toBeTrue();
});

it('creates scheduled order and sends arabic notification', function (): void {
    Notification::fake();

    $user = User::factory()->create();
    $store = SmStoreFactory::new()->create();
    $category = SmCategoryFactory::new()->create();
    $masterProduct = MasterProductFactory::new()->create();

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
        'week_days' => [now()->dayOfWeek],
        'periods' => [
            [
                'label' => 'الفترة الأولى',
                'fromTime' => now()->addMinute()->format('H:i'),
                'toTime' => now()->addHour()->format('H:i'),
            ],
        ],
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
