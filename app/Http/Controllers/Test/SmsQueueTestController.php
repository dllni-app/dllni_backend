<?php

declare(strict_types=1);

namespace App\Http\Controllers\Test;

use App\Http\Requests\Test\SmsProviderTestRequest;
use App\Jobs\Sms\SendRegistrationSmsJob;
use App\Models\SmsMessage;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\URL;
use Modules\User\Services\Sms\SmsMessageBuilder;
use Symfony\Component\HttpFoundation\Response;

final class SmsQueueTestController
{
    public function store(
        SmsProviderTestRequest $request,
        SmsMessageBuilder $smsMessageBuilder,
    ): JsonResponse {
        $this->ensureEnabled();

        $phone = (string) $request->validated('phone');
        $otp = (string) random_int(100000, 999999);
        $smsPayload = $smsMessageBuilder->registrationOtp($otp, 'ar');

        $smsMessage = SmsMessage::query()->create([
            'provider' => 'mtn',
            'gsm' => $phone,
            'message' => $smsPayload['message'],
            'lang' => $smsPayload['lang'],
            'status' => 'pending',
            'attempts_count' => 0,
            'queued_at' => now(),
        ]);

        SendRegistrationSmsJob::dispatch($smsMessage->id);
        $smsMessage->refresh();

        $statusUrl = URL::temporarySignedRoute(
            'api.test.sms-queue.status',
            now()->addHour(),
            ['smsMessage' => $smsMessage->id],
        );

        return response()->json([
            'success' => true,
            'code' => 'SMS_QUEUE_TEST_QUEUED',
            'message' => 'SMS OTP test was dispatched through the same queue job used by the real OTP flow.',
            'data' => [
                'test_id' => $smsMessage->id,
                'phone' => $phone,
                'otp' => $otp,
                'status_url' => $statusUrl,
                ...$this->diagnosticData($smsMessage),
            ],
        ], Response::HTTP_ACCEPTED);
    }

    public function show(SmsMessage $smsMessage): JsonResponse
    {
        $this->ensureEnabled();

        $smsMessage->refresh();

        return response()->json([
            'success' => true,
            'code' => 'SMS_QUEUE_TEST_STATUS',
            'message' => $this->diagnosticMessage($smsMessage),
            'data' => [
                'test_id' => $smsMessage->id,
                'phone' => $smsMessage->gsm,
                ...$this->diagnosticData($smsMessage),
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function diagnosticData(SmsMessage $smsMessage): array
    {
        $connection = (string) config('queue.default', 'sync');
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);
        $queueName = config("queue.connections.{$connection}.queue");
        $workerPickedUp = $smsMessage->job_started_at !== null;
        $finishedAt = $smsMessage->job_finished_at;

        $currentQueueWaitMs = null;
        $totalElapsedMs = null;

        if ($smsMessage->queued_at instanceof DateTimeInterface) {
            $currentQueueWaitMs = $workerPickedUp
                ? $smsMessage->queue_wait_ms
                : $this->elapsedBetweenMs($smsMessage->queued_at, now());

            $totalElapsedMs = $this->elapsedBetweenMs(
                $smsMessage->queued_at,
                $finishedAt instanceof DateTimeInterface ? $finishedAt : now(),
            );
        }

        return [
            'queue' => [
                'connection' => $connection,
                'driver' => $driver,
                'queue' => $queueName,
                'async_worker_tested' => $driver !== 'sync',
                'worker_picked_up' => $workerPickedUp,
                'status' => $smsMessage->status,
                'attempts_count' => $smsMessage->attempts_count,
                'max_attempts' => 3,
                'retry_backoff_seconds' => 60,
                'queued_at' => $this->formatDate($smsMessage->queued_at),
                'job_started_at' => $this->formatDate($smsMessage->job_started_at),
                'queue_wait_ms' => $smsMessage->queue_wait_ms,
                'current_queue_wait_ms' => $currentQueueWaitMs,
            ],
            'execution' => [
                'provider_execution_ms' => $smsMessage->provider_execution_ms,
                'provider_execution_seconds' => $smsMessage->provider_execution_ms !== null
                    ? round($smsMessage->provider_execution_ms / 1000, 3)
                    : null,
                'job_execution_ms' => $smsMessage->job_execution_ms,
                'job_execution_seconds' => $smsMessage->job_execution_ms !== null
                    ? round($smsMessage->job_execution_ms / 1000, 3)
                    : null,
                'job_finished_at' => $this->formatDate($smsMessage->job_finished_at),
                'total_elapsed_ms' => $totalElapsedMs,
                'total_elapsed_seconds' => $totalElapsedMs !== null
                    ? round($totalElapsedMs / 1000, 3)
                    : null,
            ],
            'provider' => [
                'name' => $smsMessage->provider,
                'status_code' => $smsMessage->provider_status_code,
                'response' => $smsMessage->provider_response,
                'sent_at' => $this->formatDate($smsMessage->sent_at),
                'failed_at' => $this->formatDate($smsMessage->failed_at),
            ],
            'diagnosis' => $this->diagnosticMessage($smsMessage),
        ];
    }

    private function diagnosticMessage(SmsMessage $smsMessage): string
    {
        $connection = (string) config('queue.default', 'sync');
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);

        if ($driver === 'sync') {
            return 'The queue connection is sync, so this request does not validate a Supervisor-managed asynchronous worker.';
        }

        if ($smsMessage->job_started_at === null) {
            return 'The job is still waiting for a queue worker. If this persists, check Supervisor and the configured queue connection.';
        }

        if ($smsMessage->status === 'sent') {
            return 'A queue worker picked up the job and the SMS provider accepted the request.';
        }

        if ($smsMessage->status === 'failed') {
            return 'A queue worker picked up the job, but the SMS sending attempt failed or is retrying.';
        }

        return 'A queue worker picked up the job and execution is in progress.';
    }

    private function ensureEnabled(): void
    {
        abort_unless(
            (bool) config('services.mtn_sms.test_endpoint_enabled', false),
            Response::HTTP_NOT_FOUND,
        );
    }

    private function formatDate(mixed $value): ?string
    {
        if (! $value instanceof DateTimeInterface) {
            return null;
        }

        return $value->format('Y-m-d\\TH:i:s.vP');
    }

    private function elapsedBetweenMs(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $fromSeconds = (float) $from->format('U.u');
        $toSeconds = (float) $to->format('U.u');

        return max(0, (int) round(($toSeconds - $fromSeconds) * 1000));
    }
}
