<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantGroupVoteSuggestionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'search' => ['sometimes', 'nullable', 'string', 'max:255'],
            'cuisineTypeId' => ['sometimes', 'nullable', 'integer', 'exists:cuisine_types,id'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
