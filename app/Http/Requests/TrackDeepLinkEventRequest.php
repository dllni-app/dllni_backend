<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class TrackDeepLinkEventRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', 'string', 'in:open,click,resolve'],
            'url' => ['nullable', 'string', 'max:2048'],
            'source' => ['nullable', 'string', 'max:100'],
            'medium' => ['nullable', 'string', 'max:100'],
            'campaign' => ['nullable', 'string', 'max:100'],
            'sharer_id' => ['nullable', 'integer', 'min:1'],
            'platform' => ['nullable', 'string', 'max:50'],
        ];
    }
}
