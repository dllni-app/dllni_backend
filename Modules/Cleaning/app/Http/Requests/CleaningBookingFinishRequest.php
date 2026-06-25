<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class CleaningBookingFinishRequest extends FormRequest
{
    public const TYPE_SUCCESS = 'success';
    public const TYPE_DISPUTE = 'dispute';

    public const REASON_CUSTOMER_TERMS_VIOLATION = 'customer_terms_violation';
    public const REASON_FINANCIAL_OR_VERBAL_DISPUTE = 'financial_or_verbal_dispute';
    public const REASON_FORCE_MAJEURE = 'force_majeure';
    public const REASON_OTHER = 'other';

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
            'finish_type' => ['required', Rule::in([self::TYPE_SUCCESS, self::TYPE_DISPUTE])],
            'dispute_reason_type' => [
                'required_if:finish_type,'.self::TYPE_DISPUTE,
                'nullable',
                Rule::in([
                    self::REASON_CUSTOMER_TERMS_VIOLATION,
                    self::REASON_FINANCIAL_OR_VERBAL_DISPUTE,
                    self::REASON_FORCE_MAJEURE,
                    self::REASON_OTHER,
                ]),
            ],
            'dispute_reason_note' => [
                Rule::requiredIf(fn (): bool => $this->input('finish_type') === self::TYPE_DISPUTE
                    && $this->input('dispute_reason_type') === self::REASON_OTHER),
                'nullable',
                'string',
                'max:1000',
            ],
        ];
    }

    public function isSuccessfulFinish(): bool
    {
        return $this->validated('finish_type') === self::TYPE_SUCCESS;
    }

    public function disputeReasonType(): ?string
    {
        $value = $this->validated('dispute_reason_type');

        return is_string($value) && mb_trim($value) !== '' ? mb_trim($value) : null;
    }

    public function disputeReasonNote(): ?string
    {
        $value = $this->validated('dispute_reason_note');

        if (! is_string($value)) {
            return null;
        }

        $value = mb_trim($value);

        return $value !== '' ? $value : null;
    }
}
