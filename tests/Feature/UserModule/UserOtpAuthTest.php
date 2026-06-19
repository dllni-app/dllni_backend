<?php

declare(strict_types=1);

use App\Jobs\Sms\SendRegistrationSmsJob;
use App\Models\SmsMessage;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Queue;
use Modules\User\Enums\OtpPurpose;
use Modules\User\Models\UserOtp;

it('registers and verifies account via otp', function (): void {
    // Arrange
    $phone = '+963944000111';
    $spacedPhone = '+963 944 000 111';
    Queue::fake();

    // Act (register)
    $registerResponse = $this->postJson('/api/v1/user/register', [
        'name' => 'Test User',
        'phone' => $spacedPhone,
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

it('returns login next action when registering an already verified phone', function (): void {
    $phone = '+963944000112';

    User::factory()->phoneVerified()->create([
        'phone' => $phone,
        'password' => bcrypt('password123'),
    ]);

    $response = $this->postJson('/api/v1/user/register', [
        'name' => 'Existing User',
        'phone' => '+963 944 000 112',
        'password' => 'password123',
    ]);

    $response
        ->assertConflict()
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'USER_ALREADY_REGISTERED')
        ->assertJsonPath('data.phone', $phone)
        ->assertJsonPath('data.next_action', 'login');
});

it('resends verification flow when registering an existing unverified phone', function (): void {
    $phone = '+963944000113';
    Queue::fake();

    $user = User::factory()->create([
        'phone' => $phone,
        'phone_verified_at' => null,
        'password' => bcrypt('password123'),
    ]);

    $response = $this->postJson('/api/v1/user/register', [
        'name' => 'Existing User',
        'phone' => '+963 944 000 113',
        'password' => 'password123',
    ]);

    $response
        ->assertConflict()
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'PHONE_VERIFICATION_REQUIRED')
        ->assertJsonPath('data.phone', $phone)
        ->assertJsonPath('data.next_action', 'send_otp_then_verify_phone')
        ->assertJsonPath('data.otp_sent', true);

    $smsMessage = SmsMessage::query()
        ->where('smsable_type', $user->getMorphClass())
        ->where('smsable_id', $user->id)
        ->firstOrFail();

    $this->assertDatabaseHas('sms_messages', [
        'id' => $smsMessage->id,
        'provider' => 'mtn',
        'gsm' => '963944000113',
        'status' => 'pending',
        'lang' => 0,
    ]);

    Queue::assertPushed(SendRegistrationSmsJob::class, function (SendRegistrationSmsJob $job) use ($smsMessage): bool {
        return $job->smsMessageId === $smsMessage->id;
    });
});

it('resets password using otp flow', function (): void {
    // Arrange
    $phone = '+963944000333';
    Queue::fake();

    $user = User::factory()->phoneVerified()->create([
        'phone' => $phone,
        'password' => bcrypt('old-password'),
    ]);

    // Act (request otp)
    $requestResponse = $this->postJson('/api/v1/user/reset-password', [
        'phone' => $phone,
    ]);

    // Assert (always ok)
    $requestResponse
        ->assertOk()
        ->assertJsonStructure(['success', 'code', 'message', 'data', 'expiresAt'])
        ->assertJsonPath('success', true)
        ->assertJsonPath('code', 'PASSWORD_RESET_OTP_SENT')
        ->assertJsonPath('data.phone', $phone)
        ->assertJsonPath('data.next_action', 'verify_reset_otp');

    $smsMessage = SmsMessage::query()
        ->where('smsable_type', $user->getMorphClass())
        ->where('smsable_id', $user->id)
        ->firstOrFail();

    Queue::assertPushed(SendRegistrationSmsJob::class, function (SendRegistrationSmsJob $job) use ($smsMessage): bool {
        return $job->smsMessageId === $smsMessage->id;
    });

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
    $confirmResponse
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('code', 'PASSWORD_RESET_SUCCESS');

    expect($confirmResponse->json('token'))->toBeNull();
});

it('rejects login with invalid credentials using Arabic API code', function (): void {
    $response = $this->postJson('/api/v1/user/login', [
        'phone' => '+963944000440',
        'password' => 'wrong-password',
    ]);

    $response
        ->assertUnauthorized()
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

it('rejects login when the phone is not verified', function (): void {
    $phone = '+963944000441';
    Queue::fake();

    $user = User::factory()->create([
        'phone' => $phone,
        'phone_verified_at' => null,
        'password' => bcrypt('secret123'),
    ]);

    $response = $this->postJson('/api/v1/user/login', [
        'phone' => $phone,
        'password' => 'secret123',
    ]);

    $response
        ->assertForbidden()
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'PHONE_VERIFICATION_REQUIRED')
        ->assertJsonPath('data.phone', $phone)
        ->assertJsonPath('data.next_action', 'verify_phone');

    $smsMessage = SmsMessage::query()
        ->where('smsable_type', $user->getMorphClass())
        ->where('smsable_id', $user->id)
        ->firstOrFail();

    Queue::assertPushed(SendRegistrationSmsJob::class, function (SendRegistrationSmsJob $job) use ($smsMessage): bool {
        return $job->smsMessageId === $smsMessage->id;
    });

    expect($response->json('token'))->toBeNull();
});

it('rejects login when the account is inactive', function (): void {
    $phone = '+963944000442';

    User::factory()->phoneVerified()->create([
        'phone' => $phone,
        'is_active' => false,
        'password' => bcrypt('secret123'),
    ]);

    $response = $this->postJson('/api/v1/user/login', [
        'phone' => $phone,
        'password' => 'secret123',
    ]);

    $response
        ->assertForbidden()
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'ACCOUNT_NOT_ACTIVE');

    expect($response->json('token'))->toBeNull();
});

it('returns a clear invalid otp response', function (): void {
    $phone = '+963944000443';

    User::factory()->phoneVerified()->create([
        'phone' => $phone,
        'password' => bcrypt('secret123'),
    ]);

    UserOtp::query()->create([
        'phone' => $phone,
        'purpose' => OtpPurpose::Login->value,
        'code_hash' => Hash::make('123456'),
        'attempts' => 0,
        'expires_at' => CarbonImmutable::now()->addMinutes(5),
        'consumed_at' => null,
    ]);

    $response = $this->postJson('/api/v1/user/login/verify', [
        'phone' => $phone,
        'password' => 'secret123',
        'otp' => '000000',
    ]);

    $response
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'OTP_INVALID')
        ->assertJsonStructure(['errors' => ['otp']]);
});

it('returns a clear expired otp response', function (): void {
    $phone = '+963944000446';

    User::factory()->phoneVerified()->create([
        'phone' => $phone,
        'password' => bcrypt('secret123'),
    ]);

    UserOtp::query()->create([
        'phone' => $phone,
        'purpose' => OtpPurpose::Login->value,
        'code_hash' => Hash::make('123456'),
        'attempts' => 0,
        'expires_at' => CarbonImmutable::now()->subMinute(),
        'consumed_at' => null,
    ]);

    $response = $this->postJson('/api/v1/user/login/verify', [
        'phone' => $phone,
        'password' => 'secret123',
        'otp' => '123456',
    ]);

    $response
        ->assertGone()
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'OTP_EXPIRED')
        ->assertJsonStructure(['errors' => ['otp']]);
});

it('stores fcm token during v1 user login', function (): void {
    $phone = '+963944000444';
    $spacedPhone = '+963 944 000 444';
    $user = User::factory()->phoneVerified()->create([
        'phone' => $phone,
        'password' => bcrypt('secret123'),
    ]);

    $response = $this->postJson('/api/v1/user/login', [
        'phone' => $spacedPhone,
        'password' => 'secret123',
        'fcm_token' => 'v1_user_fcm_token_1234567890',
    ]);

    $response->assertOk()->assertJsonStructure(['data', 'token']);
    expect($user->fresh()->fcm_token)->toBe('v1_user_fcm_token_1234567890');
});

it('stores fcm token from fcm-token header during v1 user login', function (): void {
    $phone = '+963944000445';
    $spacedPhone = '+963 944 000 445';
    $user = User::factory()->phoneVerified()->create([
        'phone' => $phone,
        'password' => bcrypt('secret123'),
    ]);

    $response = $this->withHeaders([
        'fcm-token' => 'v1_user_header_fcm_token_1234567890',
    ])->postJson('/api/v1/user/login', [
        'phone' => $spacedPhone,
        'password' => 'secret123',
    ]);

    $response->assertOk()->assertJsonStructure(['data', 'token']);
    expect($user->fresh()->fcm_token)->toBe('v1_user_header_fcm_token_1234567890');
});
