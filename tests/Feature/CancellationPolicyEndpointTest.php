<?php

declare(strict_types=1);

use App\Models\CancellationPolicy;
use App\Models\User;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    Sanctum::actingAs(User::factory()->create());
});

it('returns cancellation policy for given module', function () {
    CancellationPolicy::query()->where('module', 'restaurant')->delete();

    $policy = CancellationPolicy::create([
        'module' => 'restaurant',
        'name' => 'سياسة إلغاء حجوزات المطاعم',
        'description' => 'توضّح هذه السياسة شروط ورسوم إلغاء طلبات المطاعم.',
        'rules' => [
            ['minutesBefore' => 60, 'feePercent' => 0],
            ['minutesBefore' => 30, 'feePercent' => 20],
            ['minutesBefore' => 10, 'feePercent' => 50],
        ],
        'is_active' => true,
        'is_default' => true,
    ]);

    $response = $this->getJson('/api/v1/cancellation-policy?module=restaurant');

    $response->assertOk();
    $response->assertJsonPath('data.id', $policy->id);
    $response->assertJsonPath('data.module', 'restaurant');
    $response->assertJsonPath('data.isActive', true);
    $response->assertJsonPath('data.isDefault', true);
    expect($response->json('data.rules'))->toBeArray();
});

it('returns 422 when module is missing', function () {
    $response = $this->getJson('/api/v1/cancellation-policy');

    $response->assertStatus(422);
    $response->assertJsonPath('errors.module.0', 'The module field is required.');
});

it('returns 404 when policy for module does not exist', function () {
    CancellationPolicy::query()->where('module', 'cleaning')->delete();

    $response = $this->getJson('/api/v1/cancellation-policy?module=cleaning');

    $response->assertNotFound();
});

