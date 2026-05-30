<?php

declare(strict_types=1);

namespace Modules\Delivery\Http\Requests\Driver;

use Illuminate\Foundation\Http\FormRequest;

final class DriverWalletTransactionIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'page' => 'nullable|integer|min:1',
            'perPage' => 'nullable|integer|min:1|max:100',
        ];
    }
}

