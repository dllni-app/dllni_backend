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

it('reminds the customer instead of placing or deducting an irreversible order', function (): void {
    Notification::fake();
    $user = User::factory()->create();
    $store = SmStoreFactory::new()->create();
    $category = SmCategoryFactory::new()->create();
    $masterProduct = MasterProductFactory::new()->create();
    $list = SmSmartListFactory::new()->create([
        'user_id' => $user->id, 'store_id' => $store->id, 'is_active' => true,
    ]);
    SmSmartListItem::query()->create([
        'smart_list_id' => $list->id, 'master_product_id' => $masterProduct->id,
        'quantity' => 2, 'unit' => 'piece', 'is_included' => true,
    ]);
    $product = SmProduct::query()->create([
        'store_id' => $store->id, 'category_id' => $category->id,
        'master_product_id' => $masterProduct->id, 'name' => 'Milk',
        'source_type' => 'manual', 'price' => 15,
        'stock_quantity' => 10, 'is_available' => true,
    ]);
    $schedule = SmSmartListSchedule::query()->create([
        'smart_list_id' => $list->id,
        'frequency_type' => 'weekly',
        'week_days' => [now()->dayOfWeek],
        'periods' => [['label' => 'الصباح', 'fromTime' => '09:00', 'toTime' => '11:00']],
        'is_active' => true, 'next_run_at' => now()->subMinute(),
    ]);
    (new ProcessSmartListScheduleJob($schedule->id))->handle();
    (new ProcessSmartListScheduleJob($schedule->id))->handle();
    $this->assertDatabaseCount('sm_orders', 0);
    expect($product->fresh()->stock_quantity)->toBe(10)
        ->and($schedule->fresh()->last_run_at)->not->toBeNull();
    Notification::assertSentToTimes($user, \Modules\Supermarket\Notifications\SmartListShoppingReminderNotification::class, 1);
});

it('customer active reminders require an explicitly chosen store and never create a list on validation failure', function (): void {
    $user = User::factory()->create();
    $this->actingAs($user, 'sanctum');
    $schedule = [
        'frequencyType' => 'weekly',
        'weekDays' => [now()->dayOfWeek],
        'periods' => [['label' => 'الصباح', 'fromTime' => '09:00', 'toTime' => '11:00']],
        'isActive' => true,
    ];
    $this->postJson('/api/v1/user/supermarket/shopping-lists', [
        'name' => 'أسبوعية', 'schedule' => $schedule,
    ])->assertStatus(422);
    $this->assertDatabaseCount('sm_smart_lists', 0);

    $store = SmStoreFactory::new()->create();
    $response = $this->postJson('/api/v1/user/supermarket/shopping-lists', [
        'name' => 'أسبوعية', 'storeId' => $store->id, 'schedule' => $schedule,
    ])->assertCreated();
    expect($response->json('data.storeId'))->toBe($store->id)
        ->and($response->json('data.schedule.isActive'))->toBeTrue();
});
