<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SmStoreDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'storeId' => 'sometimes|required|integer|exists:sm_stores,id',
            'documentType' => 'sometimes|required|string|in:identity,commercial_registration,health_certificate,other',
            'filePath' => 'sometimes|required|string|max:255',
            'verificationStatus' => 'nullable|string|in:pending,approved,rejected',
            'rejectionReason' => 'nullable|string',
            'verifiedByUserId' => 'nullable|integer|exists:users,id',
            'verifiedAt' => 'nullable|date',
            'expiresAt' => 'nullable|date',
        ];
    }
}
