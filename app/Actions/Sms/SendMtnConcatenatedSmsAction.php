<?php

declare(strict_types=1);

namespace App\Actions\Sms;

use App\Data\Sms\MtnSmsPayloadData;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

final class SendMtnConcatenatedSmsAction
{
    /**
     * @return array{success: bool, status_code: int, body: string, gsm_count: int, lang: int}
     */
    public function execute(MtnSmsPayloadData $payload): array
    {
        $response = $this->sendRequest($payload);

        return [
            'success' => $response->successful(),
            'status_code' => $response->status(),
            'body' => $response->body(),
            'gsm_count' => count($payload->gsm),
            'lang' => $payload->lang,
        ];
    }

    private function sendRequest(MtnSmsPayloadData $payload): Response
    {
        $baseUrl = config('services.mtn_sms.base_url');

        if (! is_string($baseUrl) || $baseUrl === '') {
            throw new RuntimeException('MTN SMS base URL is not configured.');
        }

        return Http::timeout((int) config('services.mtn_sms.timeout', 15))
            ->retry(
                (int) config('services.mtn_sms.retry_times', 2),
                (int) config('services.mtn_sms.retry_sleep', 500)
            )
            ->get($baseUrl, [
                'User' => config('services.mtn_sms.user'),
                'Pass' => config('services.mtn_sms.password'),
                'From' => config('services.mtn_sms.from', 'Dllni 24'),
                'Gsm' => $payload->gsmString(),
                'Msg' => $this->encodeMessage($payload->message, $payload->lang),
                'Lang' => $payload->lang,
            ]);
    }

    private function encodeMessage(string $message, int $lang): string
    {
        if ($lang === 1) {
            return $message;
        }

        $utf16 = mb_convert_encoding($message, 'UTF-16BE', 'UTF-8');

        return mb_strtoupper(bin2hex($utf16));
    }
}
