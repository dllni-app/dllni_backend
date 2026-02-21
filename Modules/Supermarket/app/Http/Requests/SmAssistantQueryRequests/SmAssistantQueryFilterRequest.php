<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmAssistantQueryRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmAssistantQueryFilterRequest extends FormRequest
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
            'filter.storeId' => 'sometimes|integer|exists:sm_stores,id',
            'filter.inputMode' => 'sometimes|string|in:text,voice',
            'filter.matchedRecipeId' => 'sometimes|integer|exists:recipes,id',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:createdAt,-createdAt',
        ];
    }
}
