<?php

declare(strict_types=1);

namespace App\Http\Requests\Test;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SendMtnConcatenatedSmsTestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'gsm' => ['required', 'array', 'min:1', 'max:50'],
            'gsm.*' => ['required', 'string', 'regex:/^9639\d{8}$/'],
            'message' => [
                'required',
                'string',
                function (string $attribute, mixed $value, Closure $fail): void {
                    $lang = (int) $this->input('lang');
                    $max = $lang === 0 ? 201 : 459;

                    if (mb_strlen((string) $value) > $max) {
                        $fail("The {$attribute} must not be greater than {$max} characters for the selected language.");
                    }
                },
            ],
            'lang' => ['required', 'integer', Rule::in([0, 1])],
        ];
    }

    public function messages(): array
    {
        return [
            'gsm.*.regex' => 'Each GSM number must use Syrian MTN international format, for example: 9639xxxxxxxx.',
            'lang.in' => 'The lang value must be 0 for Arabic or 1 for English.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $gsm = $this->input('gsm');

        if (is_string($gsm)) {
            $gsm = array_filter(array_map('trim', explode(';', $gsm)));
        } elseif (is_array($gsm)) {
            $gsm = array_values(array_filter(array_map(static function (mixed $value): ?string {
                if (! is_scalar($value)) {
                    return null;
                }

                $value = mb_trim((string) $value);

                return $value !== '' ? $value : null;
            }, $gsm)));
        }

        $lang = $this->input('lang');

        $this->merge([
            'gsm' => $gsm,
            'lang' => is_numeric($lang) ? (int) $lang : $lang,
        ]);
    }
}
