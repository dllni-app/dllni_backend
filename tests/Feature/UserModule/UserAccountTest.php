<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;

it('returns account payload with unread notification count', function (): void {
    $user = User::factory()->create([
        'phone' => '+963911111111',
        'phone_verified_at' => Carbon::now(),
    ]);

    $user->notify(new class extends Notification
    {
        public function via(object $notifiable): array
        {
            return ['database'];
        }

        /**
         * @return array<string, mixed>
         */
        public function toArray(object $notifiable): array
        {
            return ['title' => 'Test'];
        }
    });

    Sanctum::actingAs($user);

    $response = $this->getJson('/api/v1/user/account');

    $response->assertOk()->assertJsonStructure([
        'user' => ['id', 'name', 'email', 'phone', 'phoneVerifiedAt', 'primaryImage'],
        'unreadNotificationsCount',
    ]);

    expect($response->json('unreadNotificationsCount'))->toBe(1);
});

it('requires authentication for account routes', function (): void {
    $this->getJson('/api/v1/user/account')->assertUnauthorized();
    $this->patchJson('/api/v1/user/account', ['name' => 'N'])->assertUnauthorized();
    $this->putJson('/api/v1/user/account/password', [
        'currentPassword' => 'x',
        'newPassword' => 'yyyyyyyy',
        'newPasswordConfirmation' => 'yyyyyyyy',
    ])->assertUnauthorized();
});

it('updates account name', function (): void {
    $user = User::factory()->create(['name' => 'Before Name']);
    Sanctum::actingAs($user);

    $response = $this->patchJson('/api/v1/user/account', [
        'name' => 'After Name',
    ]);

    $response->assertOk();
    expect($response->json('user.name'))->toBe('After Name');
    $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'After Name']);
});

it('clears phone verification when phone changes', function (): void {
    $user = User::factory()->create([
        'phone' => '+963922222222',
        'phone_verified_at' => Carbon::now(),
    ]);
    Sanctum::actingAs($user);

    $response = $this->patchJson('/api/v1/user/account', [
        'phone' => '+963933333333',
    ]);

    $response->assertOk();
    expect($response->json('user.phone'))->toBe('+963933333333');
    expect(User::query()->find($user->id)->phone_verified_at)->toBeNull();
});

it('rejects duplicate phone on account update', function (): void {
    User::factory()->create(['phone' => '+963944444444']);

    $user = User::factory()->create(['phone' => '+963955555555']);
    Sanctum::actingAs($user);

    $response = $this->patchJson('/api/v1/user/account', [
        'phone' => '+963944444444',
    ]);

    $response->assertUnprocessable();
});

it('updates password when current password is valid', function (): void {
    $user = User::factory()->create([
        'password' => bcrypt('oldPass12'),
    ]);
    Sanctum::actingAs($user);

    $response = $this->putJson('/api/v1/user/account/password', [
        'currentPassword' => 'oldPass12',
        'newPassword' => 'newPass12x',
        'newPasswordConfirmation' => 'newPass12x',
    ]);

    $response->assertOk()->assertJsonPath('message', 'Password updated successfully.');

    $fresh = User::query()->findOrFail($user->id);
    expect(Hash::check('newPass12x', $fresh->password))->toBeTrue();
});

it('rejects password update when confirmation does not match', function (): void {
    $user = User::factory()->create([
        'password' => bcrypt('oldPass12'),
    ]);
    Sanctum::actingAs($user);

    $response = $this->putJson('/api/v1/user/account/password', [
        'currentPassword' => 'oldPass12',
        'newPassword' => 'newPass12x',
        'newPasswordConfirmation' => 'otherPass12',
    ]);

    $response->assertUnprocessable();
});

it('rejects password update when current password is wrong', function (): void {
    $user = User::factory()->create([
        'password' => bcrypt('oldPass12'),
    ]);
    Sanctum::actingAs($user);

    $response = $this->putJson('/api/v1/user/account/password', [
        'currentPassword' => 'wrongPass12',
        'newPassword' => 'newPass12x',
        'newPasswordConfirmation' => 'newPass12x',
    ]);

    $response->assertUnprocessable();
});
