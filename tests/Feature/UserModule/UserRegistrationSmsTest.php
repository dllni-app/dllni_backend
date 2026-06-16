<?php

declare(strict_types=1);

use App\Actions\Sms\SendMtnConcatenatedSmsAction;
use App\Jobs\Sms\SendRegistrationSmsJob;
use App\Models\User;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Modules\User\Services\Sms\SmsMessageBuilder;

it('builds registration otp sms in arabic for english locale too', function (): void {
    $payload = app(SmsMessageBuilder::class)->registrationOtp('123456', 'en');

    expect($payload)->toBe([
        'message' => 'رمز التحقق الخاص بك: 123456',
        'lang' => 0,
    ]);
});

it('sends registration sms from the queued job', function (): void {
    $user = User::factory()->create([
        'phone' => '+963944000111',
    ]);

    $smsPayload = app(SmsMessageBuilder::class)->registrationOtp('123456', 'ar');

    $smsMessage = $user->smsMessages()->create([
        'provider' => 'mtn',
        'gsm' => '963944000111',
        'message' => $smsPayload['message'],
        'lang' => $smsPayload['lang'],
        'status' => 'pending',
        'attempts_count' => 0,
    ]);

    Http::fake([
        '*' => Http::response('raw provider response', 200),
    ]);

    $job = new SendRegistrationSmsJob($smsMessage->id);
    $job->handle(app(SendMtnConcatenatedSmsAction::class));

    $smsMessage->refresh();

    expect($smsMessage->status)->toBe('sent');
    expect($smsMessage->provider_status_code)->toBe(200);
    expect($smsMessage->provider_response)->toBe('raw provider response');
    expect($smsMessage->sent_at)->not->toBeNull();
    expect($smsMessage->failed_at)->toBeNull();

    Http::assertSent(function (HttpRequest $request) use ($smsPayload): bool {
        $query = [];
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return $request->method() === 'GET'
            && str_contains($request->url(), 'ConcatenatedSender.aspx')
            && ($query['Gsm'] ?? null) === '963944000111'
            && ($query['Lang'] ?? null) === (string) $smsPayload['lang']
            && ($query['Msg'] ?? null) === strtoupper(bin2hex(mb_convert_encoding($smsPayload['message'], 'UTF-16BE', 'UTF-8')));
    });
});
