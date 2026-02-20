<?php

declare(strict_types=1);

namespace App\Http\Requests\WorkerRequests;

use Illuminate\Foundation\Http\FormRequest;

final class WorkerFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'filter.trustScoreMin' => 'sometimes|integer|min:0|max:100',
            'filter.trustScoreMax' => 'sometimes|integer|min:0|max:100',
            'filter.isActive' => 'sometimes|boolean',
            'filter.isSuspended' => 'sometimes|boolean',
            'filter.search' => 'sometimes|string|max:255',
            'sort' => 'sometimes|string|in:trustScore,-trustScore,firstName,-firstName,createdAt,-createdAt',
        ];
    }
}
