<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests\Concerns;

use Illuminate\Validation\Validator;
use Modules\Cleaning\Models\CleaningEventType;

trait ValidatesDynamicCleaningEvent
{
    private function validateDynamicCleaningEvent(Validator $validator): void
    {
        $typeId = (int) $this->input('event.eventTypeId', $this->input('propertyDetails.eventTypeId', 0));
        if ($typeId <= 0) {
            return;
        }

        $type = CleaningEventType::query()
            ->where('is_active', true)
            ->with(['fields' => fn ($query) => $query->where('is_active', true)->orderBy('sort_order')])
            ->find($typeId);
        if (! $type instanceof CleaningEventType) {
            $validator->errors()->add('event.eventTypeId', 'The selected event type is not available.');
            return;
        }

        $answers = $this->input('event.dynamicAnswers', $this->input('propertyDetails.dynamicAnswers', []));
        $answers = is_array($answers) ? $answers : [];
        $allowedKeys = $type->fields->pluck('key')->all();
        foreach (array_keys($answers) as $key) {
            if (! in_array($key, $allowedKeys, true)) {
                $validator->errors()->add("event.dynamicAnswers.{$key}", 'This field is not defined for the selected event type.');
            }
        }

        foreach ($type->fields as $field) {
            $path = 'event.dynamicAnswers.'.(string) $field->key;
            $value = $answers[$field->key] ?? null;
            if ((bool) $field->is_required && ($value === null || $value === '' || $value === [])) {
                $validator->errors()->add($path, 'This field is required.');
                continue;
            }
            if ($value === null || $value === '') {
                continue;
            }

            $valid = match ((string) $field->field_type) {
                'number' => is_numeric($value),
                'yes_no' => is_bool($value) || in_array($value, [0, 1, '0', '1'], true),
                'multi_select' => is_array($value),
                'single_select' => is_scalar($value),
                default => is_scalar($value),
            };
            if (! $valid) {
                $validator->errors()->add($path, 'The answer does not match the field type.');
                continue;
            }

            $options = is_array($field->options) ? $field->options : [];
            if ($options !== [] && in_array((string) $field->field_type, ['single_select', 'multi_select'], true)) {
                $selected = is_array($value) ? $value : [$value];
                if (array_diff($selected, $options) !== []) {
                    $validator->errors()->add($path, 'One or more selected values are not available.');
                }
            }
        }
    }
}
