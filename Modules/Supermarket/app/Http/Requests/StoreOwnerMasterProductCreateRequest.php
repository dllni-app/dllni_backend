<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class StoreOwnerMasterProductCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'masterProductIds' => ['required', 'array', 'min:1'],
            'masterProductIds.*' => ['integer', 'distinct', 'exists:master_products,id'],
        ];
    }
}
