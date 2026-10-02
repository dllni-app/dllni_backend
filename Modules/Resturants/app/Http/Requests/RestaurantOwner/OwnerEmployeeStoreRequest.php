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

    protected function prepareForValidation(): void
    {
        $merge = [];

        if ($this->has('permissionIds') || $this->has('permission_ids')) {
            $permissionIds = $this->input('permissionIds', $this->input('permission_ids'));

            if (is_string($permissionIds)) {
                $decoded = json_decode($permissionIds, true);
                $permissionIds = is_array($decoded)
                    ? $decoded
                    : array_filter(array_map('trim', explode(',', $permissionIds)));
            }

            $merge['permissionIds'] = $permissionIds;
        }

        if ($this->has('isActive') || $this->has('is_active')) {
            $merge['isActive'] = $this->input('isActive', $this->input('is_active'));
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
