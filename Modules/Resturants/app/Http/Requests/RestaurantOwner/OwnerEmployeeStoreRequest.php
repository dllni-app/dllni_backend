<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\RestaurantOwner;

use Illuminate\Foundation\Http\FormRequest;

final class OwnerEmployeeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'password' => 'required|string|min:8|max:255',
            'profileImage' => 'sometimes|file|image|mimes:jpeg,jpg,png,webp|max:5120',
            'permissionIds' => 'sometimes|array',
            'permissionIds.*' => 'integer|exists:permissions,id',
            'isActive' => 'sometimes|boolean',
        ];
    }
}
