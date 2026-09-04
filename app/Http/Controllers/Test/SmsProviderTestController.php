<?php

declare(strict_types=1);

namespace App\Http\Controllers\Test;

use App\Http\Requests\Test\SmsProviderTestRequest;
use App\Jobs\Sms\SendRegistrationSmsJob;
use App\Models\SmsMessage;
use DateTimeInterface;
use Illuminate\Http\JsonResponse;
use Modules\User\Services\Sms\SmsMessageBuilder;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SmsProviderTestController
{
    public function __invoke(
        SmsProviderTestRequest $request,
        SmsMessageBuilder $smsMessageBuilder,
    ): JsonResponse {
        abort_unless(
            (bool) config('services.mtn_sms.test_endpoint_enabled', false),
            Response::HTTP_NOT_FOUND
        );

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

        try {
            SendRegistrationSmsJob::dispatch($smsMessage->id);
        } catch (Throwable $exception) {
            report($exception);
            $smsMessage->refresh();

            return $this->response($smsMessage, $phone, $otp, $exception->getMessage());
        }

        $this->waitForQueueExecution($smsMessage);
        $smsMessage->refresh();

        return $this->response($smsMessage, $phone, $otp);
    }

    private function response(
        SmsMessage $smsMessage,
        string $phone,
        string $otp,
        ?string $dispatchError = null,
    ): JsonResponse {
        $connection = (string) config('queue.default', 'sync');
        $driver = (string) config("queue.connections.{$connection}.driver", $connection);
        $queueName = config("queue.connections.{$connection}.queue");
        $workerPickedUp = $smsMessage->job_started_at !== null;
        $terminal = in_array($smsMessage->status, ['sent', 'failed'], true);
        $success = $smsMessage->status === 'sent';

        $queueWaitMs = $smsMessage->queue_wait_ms;

        if ($queueWaitMs === null && $smsMessage->queued_at instanceof DateTimeInterface) {
            $queueWaitMs = $this->elapsedBetweenMs(
                $smsMessage->queued_at,
                $smsMessage->job_started_at instanceof DateTimeInterface ? $smsMessage->job_started_at : now(),
            );
        }

        $totalElapsedMs = null;

        if ($smsMessage->queued_at instanceof DateTimeInterface) {
            $totalElapsedMs = $this->elapsedBetweenMs(
                $smsMessage->queued_at,
                $smsMessage->job_finished_at instanceof DateTimeInterface ? $smsMessage->job_finished_at : now(),
            );
        }

        $providerExecutionMs = $smsMessage->provider_execution_ms;
        $error = $dispatchError;

        if ($error === null && $smsMessage->status === 'failed') {
            $error = $smsMessage->provider_response;
        }

        $statusCode = match ($smsMessage->status) {
            'sent' => Response::HTTP_OK,
            'failed' => Response::HTTP_BAD_GATEWAY,
            default => Response::HTTP_ACCEPTED,
        };

        $code = match ($smsMessage->status) {
            'sent' => 'SMS_PROVIDER_TEST_SENT',
            'failed' => 'SMS_PROVIDER_TEST_FAILED',
            default => 'SMS_PROVIDER_TEST_QUEUED',
        };

        return response()->json([
            'success' => $success,
            'code' => $code,
            'message' => $this->diagnosticMessage($smsMessage, $driver, $dispatchError),
            'data' => [
                'phone' => $phone,
                'otp' => $otp,
                'queue' => [
                    'connection' => $connection,
                    'driver' => $driver,
                    'queue' => $queueName,
                    'async_worker_tested' => $driver !== 'sync',
                    'worker_picked_up' => $workerPickedUp,
                    'status' => $smsMessage->status,
                    'attempts_count' => $smsMessage->attempts_count,
                    'queued_at' => $this->formatDate($smsMessage->queued_at),
                    'job_started_at' => $this->formatDate($smsMessage->job_started_at),
                    'queue_wait_ms' => $queueWaitMs,
                ],
                'execution' => [
                    'provider_execution_ms' => $providerExecutionMs,
                    'provider_execution_seconds' => $providerExecutionMs !== null
                        ? round($providerExecutionMs / 1000, 3)
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
                    'error' => $error,
                    'execution_time_ms' => $providerExecutionMs,
                    'execution_time_seconds' => $providerExecutionMs !== null
                        ? round($providerExecutionMs / 1000, 3)
                        : null,
                    'sent_at' => $this->formatDate($smsMessage->sent_at),
                    'failed_at' => $this->formatDate($smsMessage->failed_at),
                ],
            ],
        ], $statusCode);
    }

    private function waitForQueueExecution(SmsMessage $smsMessage): void
    {
        $waitMs = max(0, (int) config('services.mtn_sms.test_queue_wait_ms', 5000));

        if ($waitMs === 0) {
            return;
        }

        $deadline = hrtime(true) + ($waitMs * 1_000_000);

        do {
            $smsMessage->refresh();

            if ($smsMessage->job_finished_at !== null || in_array($smsMessage->status, ['sent', 'failed'], true)) {
                return;
            }

            usleep(100_000);
        } while (hrtime(true) < $deadline);
    }

    private function diagnosticMessage(SmsMessage $smsMessage, string $driver, ?string $dispatchError): string
    {
        if ($dispatchError !== null) {
            return 'The SMS queue dispatch or synchronous execution failed.';
        }

        if ($driver === 'sync') {
            return $smsMessage->status === 'sent'
                ? 'The SMS was sent, but QUEUE_CONNECTION=sync does not validate Supervisor.'
                : 'QUEUE_CONNECTION=sync does not validate a Supervisor-managed worker.';
        }

        if ($smsMessage->job_started_at === null) {
            return 'The SMS job is still waiting for a queue worker. Check Supervisor if queue_wait_ms keeps increasing.';
        }

        if ($smsMessage->status === 'sent') {
            return 'Supervisor/queue worker picked up the real SMS job and the provider accepted the request.';
        }

        if ($smsMessage->status === 'failed') {
            return 'Supervisor/queue worker picked up the real SMS job, but SMS sending failed.';
        }

        return 'Supervisor/queue worker picked up the real SMS job and it is still executing.';
    }

    private function formatDate(mixed $value): ?string
    {
        return $value instanceof DateTimeInterface
            ? $value->format('Y-m-d\\TH:i:s.vP')
            : null;
    }

    private function elapsedBetweenMs(DateTimeInterface $from, DateTimeInterface $to): int
    {
        $fromSeconds = (float) $from->format('U.u');
        $toSeconds = (float) $to->format('U.u');

        return max(0, (int) round(($toSeconds - $fromSeconds) * 1000));
    }
}
