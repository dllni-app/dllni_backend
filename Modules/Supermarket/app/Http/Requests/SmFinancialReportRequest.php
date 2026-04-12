<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SmFinancialReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasAnyRole(['admin', 'Super Admin']) ?? false;
    }

    public function rules(): array
    {
        return [
            'startDate' => 'required|date',
            'endDate' => 'required|date|after_or_equal:startDate',
            'storeId' => 'nullable|integer|exists:sm_stores,id',
            'status' => 'nullable|string|in:pending,accepted,ready_for_pickup,completed,cancelled',
        ];
    }
}
