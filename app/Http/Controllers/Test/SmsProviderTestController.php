<?php

declare(strict_types=1);

namespace App\Http\Controllers\Test;

use App\Actions\Sms\SendMtnConcatenatedSmsAction;
use App\Data\Sms\MtnSmsPayloadData;
use App\Http\Requests\Test\SmsProviderTestRequest;
use Illuminate\Http\JsonResponse;
use Modules\User\Services\Sms\SmsMessageBuilder;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

final class SmsProviderTestController
{
    public function __invoke(
        SmsProviderTestRequest $request,
        SendMtnConcatenatedSmsAction $action,
        SmsMessageBuilder $smsMessageBuilder,
    ): JsonResponse {
        abort_unless(
            (bool) config('services.mtn_sms.test_endpoint_enabled', false),
            Response::HTTP_NOT_FOUND
        );

        $phone = (string) $request->validated('phone');
        $otp = (string) random_int(100000, 999999);
        $smsPayload = $smsMessageBuilder->registrationOtp($otp, 'ar');

        try {
            $result = $action->execute(new MtnSmsPayloadData(
                gsm: [$phone],
                message: $smsPayload['message'],
                lang: $smsPayload['lang'],
            ));
        } catch (Throwable $exception) {
            report($exception);

            return response()->json([
                'success' => false,
                'code' => 'SMS_PROVIDER_TEST_ERROR',
                'message' => 'SMS provider test failed before a successful provider response was received.',
                'data' => [
                    'phone' => $phone,
                    'otp' => $otp,
                    'provider' => [
                        'name' => 'mtn',
                        'status_code' => null,
                        'response' => null,
                        'error' => $exception->getMessage(),
                    ],
                ],
            ], Response::HTTP_BAD_GATEWAY);
        }

        $success = (bool) ($result['success'] ?? false);

        return response()->json([
            'success' => $success,
            'code' => $success ? 'SMS_PROVIDER_TEST_SENT' : 'SMS_PROVIDER_TEST_FAILED',
            'message' => $success
                ? 'SMS test OTP was accepted by the provider.'
                : 'SMS provider returned a failure response.',
            'data' => [
                'phone' => $phone,
                'otp' => $otp,
                'provider' => [
                    'name' => 'mtn',
                    'status_code' => $result['status_code'] ?? null,
                    'response' => $result['body'] ?? null,
                    'error' => null,
                ],
            ],
        ], $success ? Response::HTTP_OK : Response::HTTP_BAD_GATEWAY);
    }
}
