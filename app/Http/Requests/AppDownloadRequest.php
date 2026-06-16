<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Enums\AppDownloadType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Enum;

final class AppDownloadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'appType' => ['required', 'string', new Enum(AppDownloadType::class)],
        ];
    }
}

