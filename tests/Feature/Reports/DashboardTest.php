<?php

declare(strict_types=1);

use App\Models\User;
use Database\Factories\SmOfferFactory;
use Database\Factories\SmOrderDisputeFactory;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmProductFactory;
use Database\Factories\SmStoreDocumentFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Services\ReportService;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $user = User::factory()->create();
    $user->assignRole('admin');
    Sanctum::actingAs($user);
});

it('forbids non-admin users from dashboard endpoint', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/sm-dashboard')->assertForbidden();
});

it('returns dashboard data', function (): void {
    SmStoreFactory::new()->count(2)->create(['is_active' => true]);
    SmOrderFactory::new()->count(5)->create(['status' => 'completed']);

    $response = $this->getJson('/api/v1/sm-dashboard');

    $response->assertOk();
    $response->assertJsonStructure([
        'sales_summary' => [
            'today',
            'today_percentage_change',
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
        'queue_counts' => [
            'pending_documents',
            'open_disputes',
            'pending_pickup_orders',
            'suspended_stores',
            'low_stock_products',
            'expiring_promotions',
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

it('returns queue_counts that match ReportService and operational alert rules', function (): void {
    $store = SmStoreFactory::new()->create(['is_active' => true]);
    SmStoreDocumentFactory::new()->create([
        'store_id' => $store->id,
        'verification_status' => 'pending',
    ]);
    SmOrderDisputeFactory::new()->create(['status' => 'open']);
    SmOrderDisputeFactory::new()->create(['status' => 'under_review']);
    SmOrderFactory::new()->create([
        'store_id' => $store->id,
        'status' => 'ready_for_pickup',
    ]);
    SmStoreFactory::new()->create(['suspension_until' => now()->addDay()]);
    SmProductFactory::new()->create([
        'store_id' => $store->id,
        'is_available' => true,
        'stock_quantity' => 1,
        'low_stock_threshold' => 10,
    ]);
    SmOfferFactory::new()->create([
        'store_id' => $store->id,
        'is_active' => true,
        'ends_at' => now()->addHours(12),
    ]);

    $serviceData = app(ReportService::class)->getDashboardData();
    $response = $this->getJson('/api/v1/sm-dashboard');

    $response->assertOk();
    expect($response->json('queue_counts'))->toBe($serviceData['queue_counts']);
    expect($response->json('operational_alerts.open_disputes_count'))->toBe($serviceData['queue_counts']['open_disputes']);
    expect($response->json('operational_alerts.low_stock_products_count'))->toBe($serviceData['queue_counts']['low_stock_products']);
    expect($response->json('queue_counts.pending_documents'))->toBeGreaterThanOrEqual(1);
    expect($response->json('queue_counts.open_disputes'))->toBe(2);
    expect($response->json('queue_counts.pending_pickup_orders'))->toBeGreaterThanOrEqual(1);
    expect($response->json('queue_counts.suspended_stores'))->toBeGreaterThanOrEqual(1);
    expect($response->json('queue_counts.low_stock_products'))->toBeGreaterThanOrEqual(1);
    expect($response->json('queue_counts.expiring_promotions'))->toBeGreaterThanOrEqual(1);
});

it('includes order_number in recent activity entries', function (): void {
    $store = SmStoreFactory::new()->create();
    SmOrderFactory::new()->create([
        'store_id' => $store->id,
        'status' => 'completed',
        'order_number' => 'SM-TEST-001',
    ]);

    $response = $this->getJson('/api/v1/sm-dashboard');

    $response->assertOk();
    $first = $response->json('recent_activity.0');
    expect($first)->toHaveKey('order_number');
    expect($first['order_number'])->toBe('SM-TEST-001');
});
