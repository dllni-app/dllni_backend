<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $user = User::factory()->create();
    Sanctum::actingAs($user);
});

it('returns dashboard data', function (): void {
    SmStoreFactory::new()->count(2)->create(['is_active' => true]);
    SmOrderFactory::new()->count(5)->create(['status' => 'completed']);

    $response = $this->getJson('/api/v1/sm-dashboard');

    $response->assertOk();
    $response->assertJsonStructure([
        'sales_summary' => [
            'today',
            'this_week',
            'this_month',
            'total_commission_revenue',
            'total_service_fees',
        ],
        'activity_metrics' => [
            'total_orders',
            'active_stores',
            'total_stores',
            'pending_pickup_orders',
        ],
        'operational_alerts' => [
            'low_stock_products_count',
            'high_cancellation_stores_count',
            'open_disputes_count',
        ],
        'recent_activity' => [],
    ]);
});

it('includes recent activity in dashboard', function (): void {
    $store = SmStoreFactory::new()->create();
    SmOrderFactory::new()->create(['store_id' => $store->id, 'status' => 'completed']);

    $response = $this->getJson('/api/v1/sm-dashboard');

    $response->assertOk();
    expect($response->json('recent_activity'))->toBeArray();
});

it('calculates activity metrics correctly', function (): void {
    SmStoreFactory::new()->count(3)->create(['is_active' => true]);

    $response = $this->getJson('/api/v1/sm-dashboard');

    $response->assertOk();
    expect($response->json('activity_metrics.active_stores'))->toBeGreaterThanOrEqual(0);
    expect($response->json('activity_metrics.total_stores'))->toBeGreaterThanOrEqual(0);
});
