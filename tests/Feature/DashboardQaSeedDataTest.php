<?php

declare(strict_types=1);

use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\EventBooking;
use Modules\Delivery\Models\DeliveryCompany;
use Modules\Delivery\Models\DeliveryOrder;
use Modules\Resturants\Models\Order;
use Modules\Resturants\Models\Restaurant;
use Modules\Supermarket\Models\SmOrder;
use Modules\Supermarket\Models\SmStore;

uses(RefreshDatabase::class);

it('seeds deterministic realistic dashboard scenarios without multiplying core records', function (): void {
    $this->seed(DatabaseSeeder::class);

    $firstCounts = [
        'cleaning_qa' => CleaningBooking::query()->where('booking_number', 'like', 'CLN-QA-%')->count(),
        'event_qa' => EventBooking::query()->where('booking_number', 'like', 'EVT-QA-%')->count(),
        'delivery_companies' => DeliveryCompany::query()->count(),
        'delivery_orders' => DeliveryOrder::query()->count(),
        'restaurants' => Restaurant::query()->count(),
        'restaurant_orders' => Order::query()->count(),
        'stores' => SmStore::query()->count(),
        'supermarket_orders' => SmOrder::query()->count(),
    ];

    expect($firstCounts['cleaning_qa'])->toBe(9)
        ->and($firstCounts['event_qa'])->toBe(3)
        ->and($firstCounts['delivery_companies'])->toBeGreaterThanOrEqual(2)
        ->and($firstCounts['restaurants'])->toBeGreaterThanOrEqual(5)
        ->and($firstCounts['stores'])->toBeGreaterThanOrEqual(3);

    foreach ([
        'pending',
        'worker_assigned',
        'awaiting_start_verification',
        'in_progress',
        'awaiting_customer_completion',
        'time_extension_requested',
        'partially_completed',
        'completed',
        'cancelled',
    ] as $status) {
        expect(CleaningBooking::query()->where('status', $status)->exists())->toBeTrue();
    }

    foreach (['offered', 'in_progress', 'completed', 'cancelled', 'stopped'] as $status) {
        expect(DeliveryOrder::query()->where('status', $status)->exists())->toBeTrue();
    }

    foreach (['pending', 'preparing', 'completed', 'cancelled'] as $status) {
        expect(Order::query()->where('status', $status)->exists())->toBeTrue();
        expect(SmOrder::query()->where('status', $status)->exists())->toBeTrue();
    }

    $notifications = DB::table('notifications')->get(['data']);
    expect($notifications)->not->toBeEmpty();

    foreach ($notifications as $notification) {
        $data = json_decode((string) $notification->data, true);

        if (! is_array($data)) {
            continue;
        }

        foreach (['title', 'body', 'message'] as $field) {
            $copy = mb_trim((string) ($data[$field] ?? ''));

            if ($copy === '') {
                continue;
            }

            expect(preg_match('/\p{Arabic}/u', $copy))->toBe(
                1,
                "Seeded notification field [{$field}] is not Arabic: {$copy}",
            );
        }
    }

    $this->seed(DatabaseSeeder::class);

    $secondCounts = [
        'cleaning_qa' => CleaningBooking::query()->where('booking_number', 'like', 'CLN-QA-%')->count(),
        'event_qa' => EventBooking::query()->where('booking_number', 'like', 'EVT-QA-%')->count(),
        'delivery_companies' => DeliveryCompany::query()->count(),
        'delivery_orders' => DeliveryOrder::query()->count(),
        'restaurants' => Restaurant::query()->count(),
        'restaurant_orders' => Order::query()->count(),
        'stores' => SmStore::query()->count(),
        'supermarket_orders' => SmOrder::query()->count(),
    ];

    expect($secondCounts)->toBe($firstCounts);
});

it('does not seed dashboard demo scenarios in production', function (): void {
    config()->set('app.env', 'production');

    $this->seed(DatabaseSeeder::class);

    expect(CleaningBooking::query()->where('booking_number', 'like', 'CLN-QA-%')->count())->toBe(0)
        ->and(EventBooking::query()->where('booking_number', 'like', 'EVT-QA-%')->count())->toBe(0)
        ->and(DeliveryOrder::query()->count())->toBe(0)
        ->and(Restaurant::query()->count())->toBe(0)
        ->and(SmStore::query()->count())->toBe(0);
});
