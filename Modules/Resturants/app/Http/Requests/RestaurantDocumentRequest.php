<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantDocumentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'restaurantId' => 'required|exists:restaurants,id',
            'documentType' => 'required|string|in:identity,commercial_registration,health_certificate,other',
            'verificationStatus' => 'nullable|string|in:pending,approved,rejected',
            'filePath' => 'nullable|string|max:255',
        ];
    }
}
