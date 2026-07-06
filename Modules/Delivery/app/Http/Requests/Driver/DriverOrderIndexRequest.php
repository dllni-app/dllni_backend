<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

final class DriverOrderIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => 'nullable|string|in:WAITING_ACCEPTANCE,ACTIVE,COMPLETED,REJECTED',
            'filter' => 'nullable|array',
            'filter.status' => 'nullable|string|in:WAITING_ACCEPTANCE,ACTIVE,COMPLETED,REJECTED',
            'page' => 'nullable|integer|min:1',
            'perPage' => 'nullable|integer|min:1|max:100',
        ];
    }
}
