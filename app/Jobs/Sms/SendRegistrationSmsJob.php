<?php

declare(strict_types=1);

namespace App\Jobs\Sms;

use App\Actions\Sms\SendMtnConcatenatedSmsAction;
use App\Data\Sms\MtnSmsPayloadData;
use App\Models\SmsMessage;
use DateTimeInterface;
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
        $jobStartedAt = now();
        $jobStartedNs = hrtime(true);
        $smsMessage = SmsMessage::query()->findOrFail($this->smsMessageId);

        if ($smsMessage->job_started_at === null) {
            $smsMessage->update([
                'job_started_at' => $jobStartedAt,
                'queue_wait_ms' => $smsMessage->queued_at instanceof DateTimeInterface
                    ? $this->elapsedBetweenMs($smsMessage->queued_at, $jobStartedAt)
                    : null,
            ]);
        }

        $smsMessage->increment('attempts_count');

        $providerStartedNs = hrtime(true);

        try {
            $result = $action->execute(new MtnSmsPayloadData(
                gsm: [$smsMessage->gsm],
                message: $smsMessage->message,
                lang: (int) $smsMessage->lang,
                smsMessageId: $smsMessage->id,
            ));
        } catch (Throwable $exception) {
            $smsMessage->update([
                'provider_execution_ms' => $this->elapsedFromNs($providerStartedNs),
                'job_execution_ms' => $this->elapsedFromNs($jobStartedNs),
                'job_finished_at' => now(),
                'provider_response' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $success = (bool) ($result['success'] ?? false);

        $smsMessage->update([
            'status' => $success ? 'sent' : 'failed',
            'provider_status_code' => $result['status_code'] ?? null,
            'provider_response' => $result['body'] ?? null,
            'provider_execution_ms' => $this->elapsedFromNs($providerStartedNs),
            'job_execution_ms' => $this->elapsedFromNs($jobStartedNs),
            'job_finished_at' => now(),
            'sent_at' => $success ? now() : null,
            'failed_at' => $success ? null : now(),
        ]);

        if (! $success) {
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
                'job_finished_at' => now(),
                'provider_response' => $exception->getMessage(),
            ]);
    }

    private function elapsedFromNs(int $startedAt): int
    {
        return max(0, (int) round((hrtime(true) - $startedAt) / 1_000_000));
    }

    private function elapsedBetweenMs(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $fromSeconds = (float) $from->format('U.u');
        $toSeconds = (float) $to->format('U.u');

        return max(0, (int) round(($toSeconds - $fromSeconds) * 1000));
    }
}
