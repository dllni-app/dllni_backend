<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

trait NormalizesPhoneInput
{
    protected function normalizePhoneInput(mixed $value): ?string
    {
        if (is_array($value)) {
            $value = $value[0] ?? null;
        }

        if (! is_string($value)) {
            return null;
        }

        $value = preg_replace('/\s+/', '', $value) ?? '';

        return $value !== '' ? $value : null;
    }
}
