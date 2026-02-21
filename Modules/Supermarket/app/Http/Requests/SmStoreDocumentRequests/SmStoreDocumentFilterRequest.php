<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmStoreDocumentRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmStoreDocumentFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.storeId' => 'sometimes|integer|exists:sm_stores,id',
            'filter.documentType' => 'sometimes|string|in:identity,commercial_registration,health_certificate,other',
            'filter.verificationStatus' => 'sometimes|string|in:pending,approved,rejected',
            'sort' => 'sometimes|string|in:documentType,-documentType,verificationStatus,-verificationStatus,createdAt,-createdAt',
        ];
    }
}
