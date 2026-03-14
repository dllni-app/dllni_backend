<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SmStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = $this->route('sm_store')?->id;

        return [
            'ownerUserId' => 'sometimes|integer|exists:users,id',
            'name' => 'sometimes|string|max:255',
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('sm_stores', 'slug')->ignore($storeId),
            ],
            'description' => 'sometimes|nullable|string',
            'address' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|nullable|string|max:255',
            'neighborhood' => 'sometimes|nullable|string|max:255',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
            'phone' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'cover' => 'sometimes|nullable|string|max:255',
            'logo' => 'sometimes|nullable|string|max:255',
            'averageRating' => 'sometimes|numeric|min:0|max:5',
            'totalReviews' => 'sometimes|integer|min:0',
            'trustScore' => 'sometimes|integer|min:0',
            'warningCount' => 'sometimes|integer|min:0',
            'isActive' => 'sometimes|boolean',
            'isFeatured' => 'sometimes|boolean',
            'suspensionUntil' => 'sometimes|nullable|date',
        ];
    }
}
