<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config()->set('services.mtn_sms.test_endpoint_enabled', true);
    config()->set('services.mtn_sms.base_url', 'https://services.mtnsyr.com:7443/general/MTNSERVICES/ConcatenatedSender.aspx');
    config()->set('services.mtn_sms.user', 'test-user');
    config()->set('services.mtn_sms.password', 'test-pass');
    config()->set('services.mtn_sms.from', 'ع الندهة');
    config()->set('services.mtn_sms.timeout', 15);
    config()->set('services.mtn_sms.retry_times', 2);
    config()->set('services.mtn_sms.retry_sleep', 0);
});

it('publicly sends a random otp through the configured sms provider', function (): void {
    Http::fake([
        '*' => Http::response('provider accepted', 200),
    ]);

    $response = $this->postJson('/api/v1/test/sms-provider', [
        'phone' => '0944 000 111',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('code', 'SMS_PROVIDER_TEST_SENT')
        ->assertJsonPath('data.phone', '963944000111')
        ->assertJsonPath('data.provider.name', 'mtn')
        ->assertJsonPath('data.provider.status_code', 200)
        ->assertJsonPath('data.provider.response', 'provider accepted');

    $otp = (string) $response->json('data.otp');

    expect($otp)->toMatch('/^\d{6}$/');

    Http::assertSent(function (HttpRequest $request) use ($otp): bool {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        $message = "رمز التحقق الخاص بك: {$otp}";
        $encodedMessage = strtoupper(bin2hex(mb_convert_encoding($message, 'UTF-16BE', 'UTF-8')));

        return $request->method() === 'GET'
            && ($query['Gsm'] ?? null) === '963944000111'
            && ($query['From'] ?? null) === 'ع الندهة'
            && ($query['Lang'] ?? null) === '0'
            && ($query['Msg'] ?? null) === $encodedMessage;
    });
});

it('returns the provider failure details in the api response', function (): void {
    Http::fake([
        '*' => Http::response('provider unavailable', 503),
    ]);

    $this->postJson('/api/v1/test/sms-provider', [
        'phone' => '+963944000111',
    ])
        ->assertStatus(502)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'SMS_PROVIDER_TEST_FAILED')
        ->assertJsonPath('data.provider.status_code', 503)
        ->assertJsonPath('data.provider.response', 'provider unavailable');
});

it('returns connection or configuration errors in the api response', function (): void {
    config()->set('services.mtn_sms.base_url', null);

    $this->postJson('/api/v1/test/sms-provider', [
        'phone' => '0944000111',
    ])
        ->assertStatus(502)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'SMS_PROVIDER_TEST_ERROR')
        ->assertJsonPath('data.provider.status_code', null)
        ->assertJsonPath('data.provider.error', 'MTN SMS base URL is not configured.');
});

it('can be disabled by configuration', function (): void {
    config()->set('services.mtn_sms.test_endpoint_enabled', false);

    $this->postJson('/api/v1/test/sms-provider', [
        'phone' => '0944000111',
    ])->assertNotFound();
});

it('validates and normalizes the test phone number', function (): void {
    $this->postJson('/api/v1/test/sms-provider', [
        'phone' => 'invalid-phone',
    ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['phone']);
});
