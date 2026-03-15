<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreOwnerEmployeeStoreRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'storeId' => 'required|integer|exists:sm_stores,id',
            'name' => 'required|string|max:255',
            'email' => 'nullable|email|max:255',
            'phone' => 'nullable|string|max:30',
            'permissionIds' => 'sometimes|array',
            'permissionIds.*' => 'integer|exists:permissions,id',
            'isActive' => 'sometimes|boolean',
        ];
    }
}
