<?php

declare(strict_types=1);

use App\Actions\Sms\SendMtnConcatenatedSmsAction;
use App\Jobs\Sms\SendRegistrationSmsJob;
use App\Models\SmsMessage;
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
    config()->set('queue.default', 'database');
    config()->set('queue.connections.database.driver', 'database');
    config()->set('queue.connections.database.queue', 'default');
});

it('dispatches the same registration sms job used by the real otp flow', function (): void {
    Queue::fake();

    $response = $this->postJson('/api/v1/test/sms-queue', [
        'phone' => '0945 357 641',
    ]);

    $response
        ->assertAccepted()
        ->assertJsonPath('success', true)
        ->assertJsonPath('code', 'SMS_QUEUE_TEST_QUEUED')
        ->assertJsonPath('data.phone', '963945357641')
        ->assertJsonPath('data.queue.connection', 'database')
        ->assertJsonPath('data.queue.driver', 'database')
        ->assertJsonPath('data.queue.async_worker_tested', true)
        ->assertJsonPath('data.queue.worker_picked_up', false)
        ->assertJsonPath('data.queue.status', 'pending');

    $smsMessageId = (int) $response->json('data.test_id');
    $smsMessage = SmsMessage::query()->findOrFail($smsMessageId);

    expect((string) $response->json('data.otp'))->toMatch('/^\d{6}$/')
        ->and($response->json('data.status_url'))->toBeString()
        ->and($smsMessage->queued_at)->not->toBeNull();

    Queue::assertPushed(SendRegistrationSmsJob::class, function (SendRegistrationSmsJob $job) use ($smsMessageId): bool {
        return $job->smsMessageId === $smsMessageId;
    });
});

it('reports when an asynchronous worker has not picked up the job yet', function (): void {
    Queue::fake();

    $queued = $this->postJson('/api/v1/test/sms-queue', [
        'phone' => '0945357641',
    ])->assertAccepted();

    $statusUrl = (string) $queued->json('data.status_url');

    $this->getJson($statusUrl)
        ->assertOk()
        ->assertJsonPath('code', 'SMS_QUEUE_TEST_STATUS')
        ->assertJsonPath('data.queue.worker_picked_up', false)
        ->assertJsonPath('data.queue.status', 'pending')
        ->assertJsonPath('data.queue.job_started_at', null)
        ->assertJsonPath('data.queue.queue_wait_ms', null);
});

it('records queue pickup and provider execution timing inside the real sms job', function (): void {
    Http::fake([
        '*' => Http::response('provider accepted', 200),
    ]);

    $smsMessage = SmsMessage::query()->create([
        'provider' => 'mtn',
        'gsm' => '963945357641',
        'message' => 'رمز التحقق الخاص بك: 123456',
        'lang' => 0,
        'status' => 'pending',
        'attempts_count' => 0,
        'queued_at' => now()->subMilliseconds(250),
    ]);

    $job = new SendRegistrationSmsJob($smsMessage->id);
    $job->handle(app(SendMtnConcatenatedSmsAction::class));

    $smsMessage->refresh();

    expect($smsMessage->status)->toBe('sent')
        ->and($smsMessage->attempts_count)->toBe(1)
        ->and($smsMessage->job_started_at)->not->toBeNull()
        ->and($smsMessage->queue_wait_ms)->toBeGreaterThanOrEqual(0)
        ->and($smsMessage->provider_execution_ms)->toBeGreaterThanOrEqual(0)
        ->and($smsMessage->job_execution_ms)->toBeGreaterThanOrEqual(0)
        ->and($smsMessage->job_finished_at)->not->toBeNull()
        ->and($smsMessage->provider_status_code)->toBe(200)
        ->and($smsMessage->provider_response)->toBe('provider accepted');
});

it('requires a signed status url', function (): void {
    Queue::fake();

    $queued = $this->postJson('/api/v1/test/sms-queue', [
        'phone' => '0945357641',
    ])->assertAccepted();

    $smsMessageId = (int) $queued->json('data.test_id');

    $this->getJson("/api/v1/test/sms-queue/{$smsMessageId}")
        ->assertForbidden();
});
