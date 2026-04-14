<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Laravel\Sanctum\Sanctum;
use Modules\Supermarket\Models\SmStore;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->user = User::factory()->create(['module_type' => UserModuleType::SupermarketSeller]);
    Sanctum::actingAs($this->user);
    $this->store = SmStore::factory()->create(['owner_user_id' => $this->user->id]);
});

it('retrieves activity logs for store owner', function () {
    Activity::create([
        'log_name' => 'products',
        'description' => 'أضاف منتجاً جديداً (Test Product)',
        'causer_type' => User::class,
        'causer_id' => $this->user->id,
        'properties' => ['store_id' => $this->store->id],
    ]);

    $response = $this->getJson("/api/v1/store-owner/activity-logs?storeId={$this->store->id}");

    $response->assertSuccessful();
    expect($response->json('data'))->toHaveCount(1);
});

it('filters activity logs by log name', function () {
    Activity::create([
        'log_name' => 'products',
        'description' => 'أضاف منتجاً جديداً (Product 1)',
        'causer_type' => User::class,
        'causer_id' => $this->user->id,
        'properties' => ['store_id' => $this->store->id],
    ]);

    Activity::create([
        'log_name' => 'offers',
        'description' => 'أضاف عرضاً جديداً (Offer 1)',
        'causer_type' => User::class,
        'causer_id' => $this->user->id,
        'properties' => ['store_id' => $this->store->id],
    ]);

    $response = $this->getJson("/api/v1/store-owner/activity-logs?storeId={$this->store->id}&logName=products");

    $response->assertSuccessful();
    $logs = $response->json('data');
    expect($logs)->toHaveCount(1);
    expect($logs[0]['logName'])->toBe('products');
});

it('paginates activity logs with custom per page', function () {
    for ($i = 0; $i < 25; $i++) {
        Activity::create([
            'log_name' => 'products',
            'description' => "أضاف منتجاً جديداً (Product {$i})",
            'causer_type' => User::class,
            'causer_id' => $this->user->id,
            'properties' => ['store_id' => $this->store->id],
        ]);
    }

    $response = $this->getJson("/api/v1/store-owner/activity-logs?storeId={$this->store->id}&perPage=10");

    $response->assertSuccessful();
    $logs = $response->json('data');
    expect($logs)->toHaveCount(10);
    expect($response->json('meta.per_page'))->toBe(10);
});

it('returns activity log with causer information', function () {
    Activity::create([
        'log_name' => 'products',
        'description' => 'أضاف منتجاً جديداً (Test Product)',
        'causer_type' => User::class,
        'causer_id' => $this->user->id,
        'properties' => ['store_id' => $this->store->id],
    ]);

    $response = $this->getJson("/api/v1/store-owner/activity-logs?storeId={$this->store->id}");

    $response->assertSuccessful();
    $data = $response->json('data.0');
    expect($data)->toHaveKeys(['id', 'description', 'logName', 'causer', 'createdAt']);
    expect($data['causer']['id'])->toBe($this->user->id);
    expect($data['causer']['name'])->toBe($this->user->name);
});

it('validates store id parameter', function () {
    $response = $this->getJson('/api/v1/store-owner/activity-logs');

    $response->assertUnprocessable();
});

it('validates log name parameter', function () {
    $response = $this->getJson("/api/v1/store-owner/activity-logs?storeId={$this->store->id}&logName=invalid");

    $response->assertUnprocessable();
});

it('returns no activity logs for empty store', function () {
    $response = $this->getJson("/api/v1/store-owner/activity-logs?storeId={$this->store->id}");

    $response->assertSuccessful();
    $logs = $response->json('data');
    expect($logs)->toHaveCount(0);
});

it('authorizes store owner access', function () {
    $anotherUser = User::factory()->create(['module_type' => UserModuleType::SupermarketSeller]);
    $anotherStore = SmStore::factory()->create(['owner_user_id' => $anotherUser->id]);

    $response = $this->getJson("/api/v1/store-owner/activity-logs?storeId={$anotherStore->id}");

    $response->assertForbidden();
});
