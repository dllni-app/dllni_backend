<?php

declare(strict_types=1);

use App\Jobs\Sms\SendRegistrationSmsJob;
use App\Models\SmsMessage;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

beforeEach(function (): void {
    config()->set('services.mtn_sms.test_endpoint_enabled', true);
    config()->set('services.mtn_sms.base_url', 'https://services.mtnsyr.com:7443/general/MTNSERVICES/ConcatenatedSender.aspx');
    config()->set('services.mtn_sms.user', 'test-user');
    config()->set('services.mtn_sms.password', 'test-pass');
    config()->set('services.mtn_sms.from', 'ع الندهة');
    config()->set('services.mtn_sms.timeout', 15);
    config()->set('services.mtn_sms.retry_times', 2);
    config()->set('services.mtn_sms.retry_sleep', 0);
    config()->set('services.mtn_sms.test_queue_wait_ms', 0);
});

it('uses the real sms queue job and reports provider timing when executed synchronously', function (): void {
    config()->set('queue.default', 'sync');

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
        ->assertJsonPath('data.queue.connection', 'sync')
        ->assertJsonPath('data.queue.driver', 'sync')
        ->assertJsonPath('data.queue.async_worker_tested', false)
        ->assertJsonPath('data.queue.worker_picked_up', true)
        ->assertJsonPath('data.queue.status', 'sent')
        ->assertJsonPath('data.provider.name', 'mtn')
        ->assertJsonPath('data.provider.status_code', 200)
        ->assertJsonPath('data.provider.response', 'provider accepted');

    $otp = (string) $response->json('data.otp');

    expect($otp)->toMatch('/^\d{6}$/')
        ->and($response->json('data.queue.queue_wait_ms'))->toBeNumeric()->toBeGreaterThanOrEqual(0)
        ->and($response->json('data.execution.provider_execution_ms'))->toBeNumeric()->toBeGreaterThanOrEqual(0)
        ->and($response->json('data.execution.job_execution_ms'))->toBeNumeric()->toBeGreaterThanOrEqual(0)
        ->and($response->json('data.provider.execution_time_ms'))->toBeNumeric()->toBeGreaterThanOrEqual(0);

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

it('shows that the job is waiting when an asynchronous worker has not picked it up', function (): void {
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.driver', 'database');
    config()->set('queue.connections.database.queue', 'default');

    Queue::fake();

    $response = $this->postJson('/api/v1/test/sms-provider', [
        'phone' => '0945357641',
    ]);

    $response
        ->assertAccepted()
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'SMS_PROVIDER_TEST_QUEUED')
        ->assertJsonPath('data.phone', '963945357641')
        ->assertJsonPath('data.queue.connection', 'database')
        ->assertJsonPath('data.queue.driver', 'database')
        ->assertJsonPath('data.queue.async_worker_tested', true)
        ->assertJsonPath('data.queue.worker_picked_up', false)
        ->assertJsonPath('data.queue.status', 'pending')
        ->assertJsonPath('data.queue.job_started_at', null)
        ->assertJsonPath('data.provider.status_code', null)
        ->assertJsonPath('data.execution.provider_execution_ms', null);

    $smsMessage = SmsMessage::query()->where('gsm', '963945357641')->latest('id')->firstOrFail();

    expect($smsMessage->queued_at)->not->toBeNull()
        ->and($response->json('data.queue.queue_wait_ms'))->toBeNumeric()->toBeGreaterThanOrEqual(0);

    Queue::assertPushed(SendRegistrationSmsJob::class, function (SendRegistrationSmsJob $job) use ($smsMessage): bool {
        return $job->smsMessageId === $smsMessage->id;
    });
});

it('returns provider failure details after the real sms job executes', function (): void {
    config()->set('queue.default', 'sync');

    Http::fake([
        '*' => Http::response('provider unavailable', 503),
    ]);

    $response = $this->postJson('/api/v1/test/sms-provider', [
        'phone' => '+963944000111',
    ]);

    $response
        ->assertStatus(502)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'SMS_PROVIDER_TEST_FAILED')
        ->assertJsonPath('data.queue.worker_picked_up', true)
        ->assertJsonPath('data.provider.status_code', 503)
        ->assertJsonPath('data.provider.response', 'provider unavailable');

    expect($response->json('data.execution.provider_execution_ms'))->toBeNumeric()->toBeGreaterThanOrEqual(0);
});

it('returns connection or configuration errors from the real sms job', function (): void {
    config()->set('queue.default', 'sync');
    config()->set('services.mtn_sms.base_url', null);

    $response = $this->postJson('/api/v1/test/sms-provider', [
        'phone' => '0944000111',
    ]);

    $response
        ->assertStatus(502)
        ->assertJsonPath('success', false)
        ->assertJsonPath('code', 'SMS_PROVIDER_TEST_FAILED')
        ->assertJsonPath('data.queue.worker_picked_up', true)
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
