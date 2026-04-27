<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Supermarket\Services\StoreOwnerContextService;

final class StoreOwnerDashboardPerformanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        app(StoreOwnerContextService::class)->ownedStore();

        return true;
    }

    public function rules(): array
    {
        return [
            'range' => 'sometimes|string|in:today,week,month,year,custom',
            'from' => 'required_if:range,custom|date',
            'to' => 'required_if:range,custom|date|after_or_equal:from',
        ];
    }
}
