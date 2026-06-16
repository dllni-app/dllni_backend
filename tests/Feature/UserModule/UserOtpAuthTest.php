<?php

declare(strict_types=1);

use App\Jobs\Sms\SendRegistrationSmsJob;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use App\Models\SmsMessage;

it('registers and verifies account via otp', function (): void {
    // Arrange
    $phone = '+963944000111';
    Queue::fake();

    // Act (register)
    $registerResponse = $this->postJson('/api/v1/user/register', [
        'name' => 'Test User',
        'phone' => $phone,
        'password' => 'password123',
    ]);

    // Assert (register)
    $registerResponse->assertOk()->assertJsonStructure(['message', 'expiresAt']);
    $this->assertDatabaseHas('users', ['phone' => $phone]);

    $user = User::query()->where('phone', $phone)->firstOrFail();
    $smsMessage = SmsMessage::query()
        ->where('smsable_type', $user->getMorphClass())
        ->where('smsable_id', $user->id)
        ->firstOrFail();

    $this->assertDatabaseHas('sms_messages', [
        'id' => $smsMessage->id,
        'provider' => 'mtn',
        'gsm' => '963944000111',
        'status' => 'pending',
        'lang' => 0,
    ]);

    Queue::assertPushed(SendRegistrationSmsJob::class, function (SendRegistrationSmsJob $job) use ($smsMessage): bool {
        return $job->smsMessageId === $smsMessage->id;
    });

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

it('stores fcm token during v1 user login', function (): void {
    $phone = '+963944000444';
    $user = User::factory()->create([
        'phone' => $phone,
        'password' => bcrypt('secret123'),
    ]);

    $response = $this->postJson('/api/v1/user/login', [
        'phone' => $phone,
        'password' => 'secret123',
        'fcm_token' => 'v1_user_fcm_token_1234567890',
    ]);

    $response->assertOk()->assertJsonStructure(['data', 'token']);
    expect($user->fresh()->fcm_token)->toBe('v1_user_fcm_token_1234567890');
});

it('stores fcm token from fcm-token header during v1 user login', function (): void {
    $phone = '+963944000445';
    $user = User::factory()->create([
        'phone' => $phone,
        'password' => bcrypt('secret123'),
    ]);

    $response = $this->withHeaders([
        'fcm-token' => 'v1_user_header_fcm_token_1234567890',
    ])->postJson('/api/v1/user/login', [
        'phone' => $phone,
        'password' => 'secret123',
    ]);

    $response->assertOk()->assertJsonStructure(['data', 'token']);
    expect($user->fresh()->fcm_token)->toBe('v1_user_header_fcm_token_1234567890');
});
