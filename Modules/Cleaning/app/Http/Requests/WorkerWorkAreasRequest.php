<?php

declare(strict_types=1);

namespace Modules\Cleaning\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

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
            'zones.*.neighborhoodId' => [
                'sometimes',
                'nullable',
                'integer',
                'distinct',
                Rule::exists('cleaning_neighborhoods', 'id')->where('is_active', true),
            ],
            'zones.*.name' => ['sometimes', 'nullable', 'string', 'max:255'],
            'zones.*.isActive' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            foreach ((array) $this->input('zones', []) as $index => $zone) {
                $hasNeighborhoodId = isset($zone['neighborhoodId']) && $zone['neighborhoodId'] !== null && $zone['neighborhoodId'] !== '';
                $hasName = isset($zone['name']) && is_string($zone['name']) && mb_trim($zone['name']) !== '';

                if ($hasNeighborhoodId || $hasName) {
                    continue;
                }

                $validator->errors()->add("zones.{$index}.neighborhoodId", 'Each zone must include a neighborhood id or a zone name.');
            }
        });
    }
}
