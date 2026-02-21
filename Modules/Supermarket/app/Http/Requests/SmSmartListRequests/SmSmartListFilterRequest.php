<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmSmartListRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmSmartListFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'search' => 'sometimes|string|max:255',
            'filter.userId' => 'sometimes|integer|exists:users,id',
            'filter.isActive' => 'sometimes|boolean',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:name,-name,createdAt,-createdAt',
        ];
    }
}
