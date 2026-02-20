<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests\CleaningServiceRequests;

use Illuminate\Foundation\Http\FormRequest;

final class CleaningServiceFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.isActive' => 'sometimes|boolean',
            'filter.category' => 'sometimes|string|in:cleaning,event_assistance,other',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:name,-name,category,-category,createdAt,-createdAt',
        ];
    }
}
