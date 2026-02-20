<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\RestaurantDocumentRequests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantDocumentFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.restaurantId' => 'sometimes|exists:restaurants,id',
            'filter.documentType' => 'sometimes|string|in:identity,commercial_registration,health_certificate,other',
            'filter.verificationStatus' => 'sometimes|string|in:pending,approved,rejected',
            'sort' => 'sometimes|string|in:document_type,-document_type,verification_status,-verification_status,created_at,-created_at',
        ];
    }
}
