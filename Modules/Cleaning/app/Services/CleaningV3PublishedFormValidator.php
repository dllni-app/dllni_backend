<?php

declare(strict_types=1);

namespace Modules\Cleaning\Services;

/**
 * Exact published-catalog answer validation; never accepts client-defined
 * controls, catalog rules, monetary amounts or unsupported required inputs.
 * V3 scheduling can be previewed only after every required field is usable.
 */
final class CleaningV3PublishedFormValidator
{
    private const SIMPLE_COMPONENTS = ['text', 'numeric', 'stepper', 'select', 'multiselect'];

    /**
     * @param  list<array<string, mixed>>  $serverItems
     * @param  array<string, mixed>  $answers
     * @return array<string, mixed>
     */
    public function normalize(array $serverItems, array $answers): array
    {
        if (array_is_list($answers) && $answers !== []) {
            $this->deny('answers', 'ANSWERS_MUST_USE_STABLE_KEYS');
        }
        if (count($answers) > 100 || count($serverItems) > 100) {
            $this->deny('answers', 'CATALOG_FIELD_LIMIT_EXCEEDED');
        }

        $normalized = [];
        $catalogKeys = [];
        foreach ($serverItems as $item) {
            $key = $item['key'] ?? null;
            $component = $item['componentId'] ?? null;
            if (! is_string($key) || ! preg_match('/^[a-z][a-z0-9_.-]{0,99}$/D', $key)
                || isset($catalogKeys[$key])) {
                $this->deny('catalog', 'INVALID_PUBLISHED_FIELD_KEY');
            }
            $catalogKeys[$key] = true;
            $required = ($item['required'] ?? false) === true;
            $present = array_key_exists($key, $answers);
            $value = $answers[$key] ?? null;

            $unsupported = ! in_array($component, self::SIMPLE_COMPONENTS, true)
                || ($item['supportedByServer'] ?? false) !== true;
            if ($unsupported && ($required || $present)) {
                $this->deny($key, 'PUBLISHED_CONTROL_UNSUPPORTED');
            }
            if ($unsupported) {
                continue;
            }

            $constraints = is_array($item['constraints'] ?? null) ? $item['constraints'] : [];
            $choices = is_array($item['choices'] ?? null) ? $item['choices'] : [];
            // Text is normalized before testing presence, so whitespace-only
            // required answers cannot bypass validation or alter the fingerprint.
            if ($component === 'text' && is_string($value)) {
                $value = mb_trim($value);
            }
            $empty = $value === null || $value === ''
                || (is_array($value) && $value === []);
            if ($empty) {
                if ($required) {
                    $this->deny($key, 'REQUIRED_PUBLISHED_FIELD_MISSING');
                }

                continue;
            }
            if (isset($item['dependencies']) || isset($item['visibleWhen'])) {
                $this->deny($key, 'UNAPPROVED_CONDITIONAL_RULE');
            }

            switch ($component) {
                case 'text':
                    if (! is_string($value) || mb_strlen(mb_trim($value)) > 1000) {
                        $this->deny($key, 'INVALID_TEXT');
                    }
                    $value = mb_trim($value);
                    break;
                case 'stepper':
                case 'numeric':
                    if (! is_int($value) && ! is_float($value) && ! is_string($value)) {
                        $this->deny($key, 'INVALID_NUMBER');
                    }
                    // Published V3 quantities are canonical base-10 decimals;
                    // scientific notation or extra precision must not be
                    // coerced into a different payable quantity by floats.
                    $decimal = (string) $value;
                    if (! preg_match('/^(?:0|[1-9][0-9]{0,9})(?:\\.[0-9]{1,2})?$/D', $decimal)) {
                        $this->deny($key, 'INVALID_NUMBER');
                    }
                    $number = (float) $decimal;
                    if (($component === 'stepper' && floor($number) !== $number)
                        || (isset($constraints['min']) && $number < (float) $constraints['min'])
                        || (isset($constraints['max']) && $number > (float) $constraints['max'])) {
                        $this->deny($key, 'NUMBER_OUT_OF_RANGE');
                    }
                    if (isset($constraints['step'])) {
                        $step = (float) $constraints['step'];
                        if (! is_finite($step) || $step <= 0) {
                            $this->deny($key, 'INVALID_PUBLISHED_STEP_RULE');
                        }
                        $base = (float) ($constraints['min'] ?? 0);
                        $stepCount = ($number - $base) / $step;
                        if (abs($stepCount - round($stepCount)) > 0.000001) {
                            $this->deny($key, 'NUMBER_STEP_MISMATCH');
                        }
                    }
                    $value = $component === 'stepper' ? (int) $number : $number;
                    break;
                case 'select':
                    if (! is_string($value) || ! in_array($value, $this->choiceKeys($choices), true)) {
                        $this->deny($key, 'INVALID_CHOICE');
                    }
                    break;
                case 'multiselect':
                    if (! is_array($value) || ! array_is_list($value)
                        || count($value) !== count(array_unique($value, SORT_REGULAR))
                        || count($value) > (int) ($constraints['maxSelections'] ?? 100)) {
                        $this->deny($key, 'INVALID_MULTISELECTION');
                    }
                    foreach ($value as $selected) {
                        if (! is_string($selected) || ! in_array($selected, $this->choiceKeys($choices), true)) {
                            $this->deny($key, 'INVALID_MULTISELECTION');
                        }
                    }
                    break;
            }
            $normalized[$key] = $value;
        }

        foreach (array_keys($answers) as $key) {
            if (! isset($catalogKeys[$key])) {
                $this->deny((string) $key, 'UNKNOWN_CATALOG_FIELD');
            }
        }

        ksort($normalized);

        return $normalized;
    }

    /** @param list<array<string,mixed>> $choices
     * @return list<string>
     */
    private function choiceKeys(array $choices): array
    {
        return array_values(array_map(static fn (array $choice): string => (string) ($choice['key'] ?? ''), $choices));
    }

    private function deny(string $field, string $code): never
    {
        throw new CleaningV3VisitPlanException($field, $code);
    }
}
