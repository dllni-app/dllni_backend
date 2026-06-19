<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserCleaningOrderCompletionRejectRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:1000'],
            'message' => ['nullable', 'string', 'max:1000'],
        ];
    }

    public function completionRejectionMessage(): ?string
    {
        $message = $this->validated('message') ?? $this->validated('reason');

        if (! is_string($message)) {
            return null;
        }

        $message = mb_trim($message);

        return $message !== '' ? $message : null;
    }
}
