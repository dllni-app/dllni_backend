<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserCleaningOrdersIndexRequest extends FormRequest
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
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'filter.status' => ['sometimes', 'string', 'in:pending,worker_assigned,in_progress,completed,cancelled'],
            'filter.scheduledDate' => ['sometimes', 'date'],
            'sort' => ['sometimes', 'string', 'in:scheduledDate,-scheduledDate,createdAt,-createdAt,status,-status,totalPrice,-totalPrice'],
        ];
    }
}
