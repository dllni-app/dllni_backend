<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class WorkerWorkAreasRequest extends FormRequest
{
    public function authorize(): bool
    {
        return (bool) auth()->user()?->worker;
    }

    public function rules(): array
    {
        return [
            'zones' => ['required', 'array', 'min:1'],
            'zones.*.name' => ['required', 'string', 'max:255', 'distinct'],
            'zones.*.isActive' => ['sometimes', 'boolean'],
        ];
    }
}
