<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmCouponRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmCouponWeeklyAnalysisRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'storeId' => 'sometimes|integer|exists:sm_stores,id',
        ];
    }
}
