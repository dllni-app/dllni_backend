<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class StoreOwnerStoreUpdateRequest extends FormRequest
{
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = app(\Modules\Supermarket\Services\StoreOwnerContextService::class)->ownedStore()->id;

        return [
            'name' => 'sometimes|string|max:255',
            'slug' => ['sometimes', 'string', 'max:255', Rule::unique('sm_stores', 'slug')->ignore($storeId)],
            'description' => 'sometimes|nullable|string',
            'address' => 'sometimes|nullable|string|max:255',
            'city' => 'sometimes|nullable|string|max:255',
            'neighborhood' => 'sometimes|nullable|string|max:255',
            'latitude' => 'sometimes|nullable|numeric|between:-90,90',
            'longitude' => 'sometimes|nullable|numeric|between:-180,180',
            'phone' => 'sometimes|nullable|string|max:255',
            'email' => 'sometimes|nullable|email|max:255',
            'cover' => $this->nullableImageStringRules(),
            'logo' => $this->nullableImageStringRules(),
        ];
    }

    /** @return array<int, mixed> */
    private function nullableImageStringRules(): array
    {
        return [
            'sometimes',
            'nullable',
            'string',
            function (string $attribute, mixed $value, Closure $fail): void {
                if (! is_string($value) || $value === '' || filter_var($value, FILTER_VALIDATE_URL)) {
                    return;
                }

                $payload = str_contains($value, ',') ? explode(',', $value, 2)[1] : $value;
                $decoded = base64_decode($payload, true);

                if ($decoded === false || mb_strlen($decoded) > self::MAX_IMAGE_BYTES) {
                    $fail("The {$attribute} must be a valid image URL or base64 image up to 5 MB.");
                }
            },
        ];
    }
}
