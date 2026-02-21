<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SmSmartListRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'userId' => 'sometimes|required|integer|exists:users,id',
            'name' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'isActive' => 'sometimes|boolean',
        ];
    }
}
