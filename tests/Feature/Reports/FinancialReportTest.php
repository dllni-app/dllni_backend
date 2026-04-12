<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\Carbon;
use Database\Factories\SmOrderFactory;
use Database\Factories\SmStoreFactory;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;

beforeEach(function (): void {
    $guardName = (string) config('auth.defaults.guard', 'web');
    Role::findOrCreate('admin', $guardName);

    $user = User::factory()->create();
    $user->assignRole('admin');
    Sanctum::actingAs($user);
});

it('forbids non-admin users from financial report endpoint', function (): void {
    Sanctum::actingAs(User::factory()->create());

    $this->getJson('/api/v1/sm-reports/financial?startDate=' . Carbon::today()->toDateString() . '&endDate=' . Carbon::today()->toDateString())
        ->assertForbidden();
});

it('returns financial report with valid data', function (): void {
    $store = SmStoreFactory::new()->create();
    SmOrderFactory::new()
        ->count(5)
        ->create([
            'store_id' => $store->id,
            'status' => 'completed',
            'total_amount' => 1000,
            'service_fee' => 100,
        ]);

    $response = $this->getJson('/api/v1/sm-reports/financial?startDate=' . Carbon::today()->toDateString() . '&endDate=' . Carbon::today()->toDateString());

    $response->assertOk();
    $response->assertJsonStructure([
        'overview' => [
            'total_revenue',
            'total_service_fees',
            'total_commissions',
            'total_cancellation_fees',
            'period' => ['start_date', 'end_date'],
        ],
        'by_store' => [],
        'by_date' => [],
    ]);
});

it('filters financial report by store', function (): void {
    $store1 = SmStoreFactory::new()->create();
    $store2 = SmStoreFactory::new()->create();

    SmOrderFactory::new()->create(['store_id' => $store1->id, 'status' => 'completed', 'total_amount' => 1000]);
    SmOrderFactory::new()->create(['store_id' => $store2->id, 'status' => 'completed', 'total_amount' => 500]);

    $response = $this->getJson('/api/v1/sm-reports/financial?startDate=' . Carbon::today()->toDateString() . '&endDate=' . Carbon::today()->toDateString() . '&storeId=' . $store1->id);

    $response->assertOk();
    expect($response->json('by_store'))->toBeArray();
});

it('filters financial report by status', function (): void {
    $store = SmStoreFactory::new()->create();
    SmOrderFactory::new()->create(['store_id' => $store->id, 'status' => 'completed']);
    SmOrderFactory::new()->create(['store_id' => $store->id, 'status' => 'cancelled']);

    $response = $this->getJson('/api/v1/sm-reports/financial?startDate=' . Carbon::today()->toDateString() . '&endDate=' . Carbon::today()->toDateString() . '&status=completed');

    $response->assertOk();
});

it('validates financial report dates', function (): void {
    $response = $this->getJson('/api/v1/sm-reports/financial?startDate=' . Carbon::today()->toDateString() . '&endDate=' . Carbon::yesterday()->toDateString());

    $response->assertUnprocessable();
    $response->assertJsonValidationErrors(['endDate']);
});
