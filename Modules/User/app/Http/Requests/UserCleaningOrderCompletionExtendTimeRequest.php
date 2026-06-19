<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserCleaningOrderCompletionExtendTimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'additionalMinutes' => ['required', 'integer', 'min:0', 'max:90'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function customerMessage(): ?string
    {
        $message = $this->validated('message');

        if (! is_string($message)) {
            return null;
        }

        $message = mb_trim($message);

        return $message !== '' ? $message : null;
    }
}
