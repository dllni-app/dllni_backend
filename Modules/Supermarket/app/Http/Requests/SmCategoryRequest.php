<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SmCategoryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $categoryId = $this->route('sm_category');

        return [
            'storeId' => 'sometimes|required|integer|exists:sm_stores,id',
            'name' => 'sometimes|required|string|max:255',
            'slug' => [
                'sometimes',
                'required',
                'string',
                'max:255',
                Rule::unique('sm_categories', 'slug')
                    ->where('store_id', $this->input('storeId'))
                    ->ignore($categoryId),
            ],
            'description' => 'nullable|string',
            'sortOrder' => 'sometimes|integer|min:0',
            'imagePath' => 'nullable|string|max:255',
            'isActive' => 'sometimes|boolean',
        ];
    }
}
