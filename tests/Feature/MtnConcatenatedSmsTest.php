<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

it('sends concatenated sms through the temporary test endpoint', function (): void {
    config()->set('services.mtn_sms.test_endpoint_enabled', true);
    config()->set('services.mtn_sms.base_url', 'https://services.mtnsyr.com:7443/general/MTNSERVICES/ConcatenatedSender.aspx');
    config()->set('services.mtn_sms.user', 'test-user');
    config()->set('services.mtn_sms.password', 'test-pass');
    config()->set('services.mtn_sms.from', 'Dllni 24');
    config()->set('services.mtn_sms.timeout', 15);
    config()->set('services.mtn_sms.retry_times', 2);
    config()->set('services.mtn_sms.retry_sleep', 500);

    Http::fake([
        '*' => Http::response('raw provider response', 200),
    ]);

    $response = $this->postJson('/api/v1/test/mtn/concatenated-sms/send', [
        'gsm' => [
            '963944000111',
            '963944000222',
        ],
        'message' => 'مرحبا بكم',
        'lang' => 0,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'SMS request has been sent to provider.')
        ->assertJsonPath('data.status', 'sent')
        ->assertJsonPath('data.provider_status_code', 200)
        ->assertJsonPath('data.provider_response', 'raw provider response')
        ->assertJsonPath('data.gsm_count', 2)
        ->assertJsonPath('data.lang', 0);

    Http::assertSent(function (HttpRequest $request): bool {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_contains($request->url(), 'ConcatenatedSender.aspx')
            && ($query['User'] ?? null) === 'test-user'
            && ($query['Pass'] ?? null) === 'test-pass'
            && ($query['From'] ?? null) === 'Dllni 24'
            && ($query['Gsm'] ?? null) === '963944000111;963944000222'
            && ($query['Lang'] ?? null) === '0'
            && ($query['Msg'] ?? null) === '06450631062D062806270020062806430645';
    });
});

it('returns 404 when the temporary test endpoint is disabled', function (): void {
    config()->set('services.mtn_sms.test_endpoint_enabled', false);

    $this->postJson('/api/v1/test/mtn/concatenated-sms/send', [
        'gsm' => '963944000111',
        'message' => 'مرحبا بكم',
        'lang' => 0,
    ])->assertNotFound();
});
