<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CleaningBookingCompleteRequest extends FormRequest
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
            'completionMessage' => ['nullable', 'string', 'max:1000'],
            'completion_message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function completionMessage(): ?string
    {
        $message = $this->validated('completionMessage') ?? $this->validated('completion_message');

        if (! is_string($message)) {
            return null;
        }

        $message = mb_trim($message);

        return $message !== '' ? $message : null;
    }
}
