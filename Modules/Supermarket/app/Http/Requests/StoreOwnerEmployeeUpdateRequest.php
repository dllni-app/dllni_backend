<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOwnerEmployeeUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();
        $payload = [];

        if (! array_key_exists('permissionIds', $input) && array_key_exists('permissionIds[]', $input)) {
            $payload['permissionIds'] = $input['permissionIds[]'];
        }

        $syncPermissionsInput = $this->input(
            'syncPermissions',
            $this->input('sync_permissions')
        );
        $syncPermissions = $syncPermissionsInput === null
            ? null
            : filter_var($syncPermissionsInput, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

        if ($syncPermissions !== null) {
            $payload['syncPermissions'] = $syncPermissions;
        }

        $hasPermissionIds = array_key_exists('permissionIds', $input)
            || array_key_exists('permissionIds[]', $input);

        if ($syncPermissions === true && ! $hasPermissionIds) {
            $payload['permissionIds'] = [];
        }

        $this->merge($payload);
    }

    public function rules(): array
    {
        return [
            'name' => 'sometimes|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'phone' => 'sometimes|nullable|string|max:30',
            'profileImage' => 'sometimes|file|image|mimes:jpeg,jpg,png,webp|max:5120',
            'permissionIds' => 'sometimes|array',
            'permissionIds.*' => [
                'integer',
                Rule::exists('permissions', 'id')->where(
                    static fn ($query) => $query->where('group', 'supermarket_owner')
                ),
            ],
            'syncPermissions' => 'sometimes|boolean',
            'isActive' => 'sometimes|boolean',
        ];
    }
}
