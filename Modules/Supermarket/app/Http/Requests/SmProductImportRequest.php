<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SmProductImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'storeId' => 'required|integer|exists:sm_stores,id',
            'categoryId' => 'required|integer|exists:sm_categories,id',
            'file' => 'required|file|mimes:csv,txt,xlsx,xls|max:10240',
        ];
    }
}
