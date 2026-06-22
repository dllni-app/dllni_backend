<?php

declare(strict_types=1);

namespace Modules\Resturants\Http\Requests\RestaurantOwner;

use Illuminate\Foundation\Http\FormRequest;

final class OwnerEmployeeUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'phone' => 'sometimes|nullable|string|max:30',
            'password' => 'sometimes|nullable|string|min:8|max:255',
            'profileImage' => 'sometimes|file|image|mimes:jpeg,jpg,png,webp|max:5120',
            'permissionIds' => 'sometimes|array',
            'permissionIds.*' => 'integer|exists:permissions,id',
            'isActive' => 'sometimes|boolean',
        ];
    }

    protected function prepareForValidation(): void
    {
        $permissionIds = $this->input('permissionIds', $this->input('permission_ids'));

        if (is_string($permissionIds)) {
            $decoded = json_decode($permissionIds, true);
            $permissionIds = is_array($decoded)
                ? $decoded
                : array_filter(array_map('trim', explode(',', $permissionIds)));
        }

        $payload = [
            'isActive' => $this->input('isActive', $this->input('is_active')),
        ];

        if ($permissionIds !== null) {
            $payload['permissionIds'] = $permissionIds;
        }

        $this->merge($payload);
    }
}
