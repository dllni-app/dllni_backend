<?php

declare(strict_types=1);

namespace App\Http\Resources\Sms;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

final class MtnSmsSendResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'success' => (bool) data_get($this->resource, 'success'),
            'message' => data_get($this->resource, 'success')
                ? 'SMS request has been sent to provider.'
                : 'SMS request failed.',
            'data' => [
                'status' => data_get($this->resource, 'success') ? 'sent' : 'failed',
                'provider_status_code' => data_get($this->resource, 'status_code'),
                'provider_response' => data_get($this->resource, 'body'),
                'gsm_count' => data_get($this->resource, 'gsm_count'),
                'lang' => data_get($this->resource, 'lang'),
            ],
        ];
    }
}
