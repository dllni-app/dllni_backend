<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Cache;

it('registers and verifies account via otp', function (): void {
    // Arrange
    $phone = '+963944000111';

    // Act (register)
    $registerResponse = $this->postJson('/api/v1/user/register', [
        'name' => 'Test User',
        'email' => 'test.user@example.com',
        'phone' => $phone,
        'password' => 'password123',
    ]);

    // Assert (register)
    $registerResponse->assertOk()->assertJsonStructure(['message', 'expiresAt']);
    $this->assertDatabaseHas('users', ['phone' => $phone]);

    // Arrange (get otp from cache in testing)
    $otp = Cache::get(sprintf('user_otp_plain:%s:%s', 'register', $phone));
    expect($otp)->toBeString();

    // Act (verify)
    $verifyResponse = $this->postJson('/api/v1/user/verify-account', [
        'phone' => $phone,
        'otp' => $otp,
    ]);

    // Assert (verify)
    $verifyResponse->assertOk()->assertJsonStructure([
        'user' => ['id', 'name', 'email', 'phone'],
        'token',
    ]);

    expect(User::query()->where('phone', $phone)->value('phone_verified_at'))->not->toBeNull();
});

// it('logs in using otp flow and returns token on verify', function (): void {
//    // Arrange
//    $phone = '+963944000222';
//    $user = User::factory()->create([
//        'phone' => $phone,
//        'password' => bcrypt('secret123'),
//    ]);
//
//    // Act (request otp)
//    $loginResponse = $this->postJson('/api/v1/user/login', [
//        'phone' => $phone,
//        'password' => 'secret123',
//    ]);
//
//    // Assert (otp sent)
//    $loginResponse->assertOk()->assertJsonStructure(['message', 'expiresAt']);
//
//    // Arrange (get otp)
//    $otp = Cache::get(sprintf('user_otp_plain:%s:%s', 'login', $phone));
//    expect($otp)->toBeString();
//
//    // Act (verify)
//    $verifyResponse = $this->postJson('/api/v1/user/login/verify', [
//        'phone' => $phone,
//        'password' => 'secret123',
//        'otp' => $otp,
//    ]);
//
//    // Assert
//    $verifyResponse->assertOk()->assertJsonStructure([
//        'user' => ['id', 'name', 'email', 'phone'],
//        'token',
//    ]);
// });

it('resets password using otp flow', function (): void {
    // Arrange
    $phone = '+963944000333';
    User::factory()->create([
        'phone' => $phone,
        'password' => bcrypt('old-password'),
    ]);

    // Act (request otp)
    $requestResponse = $this->postJson('/api/v1/user/reset-password', [
        'phone' => $phone,
    ]);

    // Assert (always ok)
    $requestResponse->assertOk()->assertJsonStructure(['message', 'expiresAt']);

    // Arrange (get otp)
    $otp = Cache::get(sprintf('user_otp_plain:%s:%s', 'reset_password', $phone));
    expect($otp)->toBeString();

    // Act (confirm)
    $confirmResponse = $this->postJson('/api/v1/user/reset-password/confirm', [
        'phone' => $phone,
        'otp' => $otp,
        'password' => 'new-password123',
        'password_confirmation' => 'new-password123',
    ]);

    // Assert
    $confirmResponse->assertOk()->assertJsonPath('message', 'تم إعادة تعيين كلمة المرور.');
});
