<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests\SmCartRequests;

use Illuminate\Foundation\Http\FormRequest;

final class SmCartFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.userId' => 'sometimes|integer|exists:users,id',
            'sort' => 'sometimes|string|in:createdAt,-createdAt',
        ];
    }
}
