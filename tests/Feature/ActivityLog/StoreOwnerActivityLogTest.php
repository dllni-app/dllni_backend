<?php

declare(strict_types=1);

use App\Enums\UserModuleType;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
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

    $response = $this->getJson('/api/v1/store-owner/activity-logs');

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

    $response = $this->getJson('/api/v1/store-owner/activity-logs?logName=products');

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

    $response = $this->getJson('/api/v1/store-owner/activity-logs?perPage=10');

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

    $response = $this->getJson('/api/v1/store-owner/activity-logs');

    $response->assertSuccessful();
    $data = $response->json('data.0');
    expect($data)->toHaveKeys(['id', 'description', 'logName', 'causer', 'createdAt']);
    expect($data['causer']['id'])->toBe($this->user->id);
    expect($data['causer']['name'])->toBe($this->user->name);
});

it('returns causer profile image URL from the media library', function () {
    $disk = (string) (config('media-library.disk_name') ?: config('filesystems.default', 'public'));
    Storage::fake($disk);

    $this->user
        ->addMedia(UploadedFile::fake()->image('avatar.png', 64, 64))
        ->toMediaCollection('primary-image');

    Activity::create([
        'log_name' => 'products',
        'description' => 'أضاف منتجاً جديداً (Test Product)',
        'causer_type' => User::class,
        'causer_id' => $this->user->id,
        'properties' => ['store_id' => $this->store->id],
    ]);

    $response = $this->getJson('/api/v1/store-owner/activity-logs');

    $response->assertSuccessful();
    expect($response->json('data.0.causer.avatarUrl'))
        ->toBe($this->user->getFirstMediaUrl('primary-image'));
});

it('returns successful empty listing without optional filters', function () {
    $response = $this->getJson('/api/v1/store-owner/activity-logs');

    $response->assertSuccessful();
    expect($response->json('data'))->toBeArray();
});

it('validates log name parameter', function () {
    $response = $this->getJson('/api/v1/store-owner/activity-logs?logName=invalid');

    $response->assertUnprocessable();
});

it('returns no activity logs for empty store', function () {
    $response = $this->getJson('/api/v1/store-owner/activity-logs');

    $response->assertSuccessful();
    $logs = $response->json('data');
    expect($logs)->toHaveCount(0);
});

it('rejects users who are not supermarket sellers', function () {
    $customer = User::factory()->create(['module_type' => null]);
    Sanctum::actingAs($customer);

    $response = $this->getJson('/api/v1/store-owner/activity-logs');

    $response->assertForbidden();
});
