<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Sanctum\Sanctum;

it('moves a registered fcm token from any previous user to the current user', function (): void {
    $sharedToken = 'shared_fcm_token_1234567890';
    $previousUser = User::factory()->create([
        'fcm_token' => $sharedToken,
    ]);
    $currentUser = User::factory()->create([
        'fcm_token' => null,
    ]);

    Sanctum::actingAs($currentUser);

    $this->putJson('/api/v1/user/notifications/token', [
        'fcmToken' => $sharedToken,
    ])->assertOk();

    expect($currentUser->fresh()->fcm_token)->toBe($sharedToken);
    expect($previousUser->fresh()->fcm_token)->toBeNull();
});

it('moves a header-synced fcm token from any previous user to the current user', function (): void {
    $sharedToken = 'header_shared_fcm_token_1234567890';
    $previousUser = User::factory()->create([
        'fcm_token' => $sharedToken,
    ]);
    $currentUser = User::factory()->create([
        'fcm_token' => null,
    ]);

    Sanctum::actingAs($currentUser);

    $this->withHeader('fcm-token', $sharedToken)
        ->getJson('/api/v1/user/notifications')
        ->assertOk();

    expect($currentUser->fresh()->fcm_token)->toBe($sharedToken);
    expect($previousUser->fresh()->fcm_token)->toBeNull();
});
