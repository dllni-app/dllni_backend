<?php

declare(strict_types=1);

namespace App\Jobs\Sms;

use App\Actions\Sms\SendMtnConcatenatedSmsAction;
use App\Data\Sms\MtnSmsPayloadData;
use App\Models\SmsMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use RuntimeException;
use Throwable;

final class SendRegistrationSmsJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $backoff = 60;

    public function __construct(
        public readonly int $smsMessageId,
    ) {
        $this->afterCommit();
    }

    public function handle(SendMtnConcatenatedSmsAction $action): void
    {
        $smsMessage = SmsMessage::query()->findOrFail($this->smsMessageId);

        $smsMessage->increment('attempts_count');

        $result = $action->execute(new MtnSmsPayloadData(
            gsm: [$smsMessage->gsm],
            message: $smsMessage->message,
            lang: (int) $smsMessage->lang,
            smsMessageId: $smsMessage->id,
        ));

        $smsMessage->update([
            'status' => $result['success'] ? 'sent' : 'failed',
            'provider_status_code' => $result['status_code'] ?? null,
            'provider_response' => $result['body'] ?? null,
            'sent_at' => $result['success'] ? now() : null,
            'failed_at' => $result['success'] ? null : now(),
        ]);

        if (! $result['success']) {
            throw new RuntimeException('MTN SMS provider returned failure response.');
        }
    }

    public function failed(Throwable $exception): void
    {
        SmsMessage::query()
            ->whereKey($this->smsMessageId)
            ->update([
                'status' => 'failed',
                'failed_at' => now(),
                'provider_response' => $exception->getMessage(),
            ]);
    }
}
