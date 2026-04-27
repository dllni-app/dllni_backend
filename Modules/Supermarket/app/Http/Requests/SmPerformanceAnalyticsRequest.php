<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Supermarket\Services\StoreOwnerContextService;

final class SmPerformanceAnalyticsRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(StoreOwnerContextService::class)->ownedStore();

        return true;
    }

    public function rules(): array
    {
        return [
            'startDate' => 'required|date',
            'endDate' => 'required|date|after_or_equal:startDate',
            'storeId' => 'nullable|integer|exists:sm_stores,id',
        ];
    }
}
