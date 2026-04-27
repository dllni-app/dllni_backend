<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Carbon\Carbon;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;

beforeEach(function (): void {
    $owner = User::factory()->create([
        'module_type' => UserModuleType::SupermarketSeller,
    ]);
    $store = SmStoreFactory::new()->create([
        'owner_user_id' => $owner->id,
    ]);

    Sanctum::actingAs($owner);

    $this->owner = $owner;
    $this->store = $store;
});

it('forbids non-supermarket-seller users from performance analytics endpoint', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/sm-reports/performance?startDate=' . Carbon::today()->toDateString() . '&endDate=' . Carbon::today()->toDateString())
        ->assertForbidden();
});

it('returns performance analytics with valid data', function (): void {
    SmOrderFactory::new()
        ->count(5)
        ->create([
            'store_id' => $this->store->id,
            'status' => 'completed',
        ]);

    $response = $this->getJson('/api/v1/sm-reports/performance?startDate=' . Carbon::today()->toDateString() . '&endDate=' . Carbon::today()->toDateString());

    $response->assertOk();
    $response->assertJsonStructure([
        'top_products' => [],
        'top_stores' => [],
        'operational_metrics' => [
            'average_basket_value',
            'completion_rate',
            'cancellation_rate',
            'total_orders',
        ],
        'trends' => [],
        'period' => ['start_date', 'end_date'],
    ]);
});

it('filters performance analytics by store', function (): void {
    SmOrderFactory::new()->create(['store_id' => $this->store->id, 'status' => 'completed']);

    $response = $this->getJson('/api/v1/sm-reports/performance?startDate=' . Carbon::today()->toDateString() . '&endDate=' . Carbon::today()->toDateString() . '&storeId=' . $this->store->id);

    $response->assertOk();
});

it('validates performance analytics dates', function (): void {
    $response = $this->getJson('/api/v1/sm-reports/performance?startDate=' . Carbon::today()->toDateString() . '&endDate=' . Carbon::yesterday()->toDateString());

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['endDate']);
});
