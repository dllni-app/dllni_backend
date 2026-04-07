<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class RestaurantGroupVoteInviteUsersRequest extends FormRequest
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
            'userIds' => ['required', 'array', 'min:1', 'max:50'],
            'userIds.*' => ['required', 'integer', 'distinct', 'exists:users,id'],
        ];
    }
}
