<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class CleaningBookingPriceAdjustmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     */
    public function rules(): array
    {
        return [
            'proposed_total_price' => ['required', 'numeric', 'min:1', 'max:999999999'],
            'reason' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'proposed_total_price.required' => 'يرجى إدخال السعر الجديد المقترح.',
            'proposed_total_price.numeric' => 'يرجى إدخال رقم صحيح للسعر الجديد المقترح.',
            'proposed_total_price.min' => 'يجب أن يكون السعر الجديد المقترح أكبر من صفر.',
            'proposed_total_price.max' => 'السعر الجديد المقترح كبير جداً.',
            'reason.string' => 'سبب التعديل يجب أن يكون نصاً.',
            'reason.max' => 'سبب التعديل يجب ألا يتجاوز 1000 حرف.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $merge = [];

        if (! $this->has('proposed_total_price') && $this->has('proposedTotalPrice')) {
            $merge['proposed_total_price'] = $this->input('proposedTotalPrice');
        }

        if ($this->has('reason') && is_string($this->input('reason'))) {
            $merge['reason'] = trim((string) $this->input('reason'));
        }

        if (($merge['reason'] ?? null) === '') {
            $merge['reason'] = null;
        }

        if ($merge !== []) {
            $this->merge($merge);
        }
    }
}
