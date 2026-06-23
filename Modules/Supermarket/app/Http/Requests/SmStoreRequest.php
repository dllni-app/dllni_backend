<?php

declare(strict_types=1);

namespace Modules\Supermarket\Http\Requests;

use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SmStoreRequest extends FormRequest
{
    private const MAX_IMAGE_BYTES = 5 * 1024 * 1024;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $storeId = $this->route('sm_store')?->id;

        return [
            'ownerUserId' => 'sometimes|integer|exists:users,id',
            'name' => 'sometimes|string|max:255',
            'slug' => [
                'sometimes',
                'string',
                'max:255',
                Rule::unique('sm_stores', 'slug')->ignore($storeId),
            ],
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
            'averageRating' => 'sometimes|numeric|min:0|max:5',
            'totalReviews' => 'sometimes|integer|min:0',
            'trustScore' => 'sometimes|integer|min:0',
            'warningCount' => 'sometimes|integer|min:0',
            'isActive' => 'sometimes|boolean',
            'isFeatured' => 'sometimes|boolean',
            'suspensionUntil' => 'sometimes|nullable|date',
        ];
    }

    /**
     * Allows the mobile app to send either an existing URL/path or a base64 image string.
     * Base64 images may be raw base64 or a data URI such as data:image/png;base64,...
     *
     * @return array<int, mixed>
     */
    private function nullableImageStringRules(): array
    {
        return [
            'sometimes',
            'nullable',
            'string',
            function (string $attribute, mixed $value, Closure $fail): void {
                if ($value === null || $value === '') {
                    return;
                }

                if (! is_string($value)) {
                    $fail(__('validation.string', ['attribute' => $attribute]));

                    return;
                }

                if ($this->isUrlOrStoragePath($value)) {
                    return;
                }

                $decodedImage = $this->decodeBase64Image($value);

                if ($decodedImage === null) {
                    $fail(__('The :attribute field must be a valid URL, storage path, or base64 encoded image.', ['attribute' => $attribute]));

                    return;
                }

                if (strlen($decodedImage) > self::MAX_IMAGE_BYTES) {
                    $fail(__('The :attribute image must not be greater than 5 MB.', ['attribute' => $attribute]));

                    return;
                }

                $imageInfo = @getimagesizefromstring($decodedImage);

                if ($imageInfo === false || ! in_array($imageInfo['mime'] ?? null, ['image/jpeg', 'image/png', 'image/webp'], true)) {
                    $fail(__('The :attribute field must be a JPEG, PNG, or WEBP image.', ['attribute' => $attribute]));
                }
            },
        ];
    }

    private function isUrlOrStoragePath(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_URL) !== false) {
            return strlen($value) <= 2048;
        }

        return strlen($value) <= 255;
    }

    private function decodeBase64Image(string $value): ?string
    {
        $base64 = trim($value);

        if (preg_match('/^data:image\/(?:jpeg|jpg|png|webp);base64,(?<data>.+)$/is', $base64, $matches) === 1) {
            $base64 = $matches['data'];
        }

        $base64 = preg_replace('/\s+/', '', $base64);

        if (! is_string($base64) || $base64 === '') {
            return null;
        }

        $decoded = base64_decode($base64, true);

        return $decoded === false ? null : $decoded;
    }
}
