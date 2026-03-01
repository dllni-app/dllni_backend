<?php

declare(strict_types=1);

namespace App\Http\Requests\UserNotificationRequests;

use Illuminate\Foundation\Http\FormRequest;

final class UserNotificationIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'perPage' => 'sometimes|integer|min:1|max:100',
            'page' => 'sometimes|integer|min:1',
            'filter.unread' => 'sometimes|boolean',
        ];
    }
}
