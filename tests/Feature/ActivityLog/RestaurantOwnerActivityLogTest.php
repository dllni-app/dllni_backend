<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\Sanctum;
use Modules\Resturants\Models\Restaurant;
use Spatie\Activitylog\Models\Activity;

beforeEach(function () {
    $this->user = User::factory()->create(['module_type' => UserModuleType::RestaurantSeller]);
    Sanctum::actingAs($this->user);
    $this->restaurant = Restaurant::factory()->create(['user_id' => $this->user->id]);
});

it('retrieves activity logs for restaurant owner', function () {
    Activity::create([
        'log_name' => 'products',
        'description' => 'أضاف منتجاً جديداً (Test Product)',
        'causer_type' => User::class,
        'causer_id' => $this->user->id,
        'properties' => ['restaurant_id' => $this->restaurant->id],
    ]);

    $response = $this->getJson('/api/v1/restaurant-owner/activity-logs');

    $response->assertSuccessful();
    expect($response->json('data'))->toHaveCount(1);
});

it('filters activity logs by log name', function () {
    Activity::create([
        'log_name' => 'products',
        'description' => 'أضاف منتجاً جديداً (Product 1)',
        'causer_type' => User::class,
        'causer_id' => $this->user->id,
        'properties' => ['restaurant_id' => $this->restaurant->id],
    ]);

    Activity::create([
        'log_name' => 'offers',
        'description' => 'أضاف عرضاً جديداً (Offer 1)',
        'causer_type' => User::class,
        'causer_id' => $this->user->id,
        'properties' => ['restaurant_id' => $this->restaurant->id],
    ]);

    $response = $this->getJson('/api/v1/restaurant-owner/activity-logs?logName=products');

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
            'properties' => ['restaurant_id' => $this->restaurant->id],
        ]);
    }

    $response = $this->getJson('/api/v1/restaurant-owner/activity-logs?perPage=10');

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
        'properties' => ['restaurant_id' => $this->restaurant->id],
    ]);

    $response = $this->getJson('/api/v1/restaurant-owner/activity-logs');

    $response->assertSuccessful();
    $data = $response->json('data.0');
    expect($data)->toHaveKeys(['id', 'description', 'logName', 'causer', 'createdAt']);
    expect($data['causer']['id'])->toBe($this->user->id);
    expect($data['causer']['name'])->toBe($this->user->name);
});

it('returns a null avatar when the causer has no avatar attribute', function () {
    Activity::create([
        'log_name' => 'products',
        'description' => 'أضاف منتجاً جديداً (Test Product)',
        'causer_type' => User::class,
        'causer_id' => $this->user->id,
        'properties' => ['restaurant_id' => $this->restaurant->id],
    ]);

    Model::preventAccessingMissingAttributes();

    try {
        $response = $this->getJson('/api/v1/restaurant-owner/activity-logs');

        $response
            ->assertSuccessful()
            ->assertJsonPath('data.0.causer.avatarUrl', null);
    } finally {
        Model::preventAccessingMissingAttributes(false);
    }
});

it('validates log name parameter', function () {
    $response = $this->getJson('/api/v1/restaurant-owner/activity-logs?logName=invalid');

    $response->assertUnprocessable();
});

it('returns no activity logs for empty restaurant', function () {
    $response = $this->getJson('/api/v1/restaurant-owner/activity-logs');

    $response->assertSuccessful();
    $logs = $response->json('data');
    expect($logs)->toHaveCount(0);
});
