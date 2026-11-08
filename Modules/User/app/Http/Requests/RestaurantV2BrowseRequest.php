<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

abstract class RestaurantV2BrowseRequest extends FormRequest
{
    /** @return array<string, array<int, mixed>> */
    abstract public function rules(): array;

    final public function authorize(): bool
    {
        return true;
    }

    final public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->has('latitude') !== $this->has('longitude')) {
                $validator->errors()->add('latitude', 'Latitude and longitude must be provided together.');
            }
        });
    }

    final public function pageNumber(): int
    {
        return (int) $this->integer('page', 1);
    }

    final public function pageSize(): int
    {
        return (int) $this->integer('perPage', 20);
    }

    /** @return array{latitude: float, longitude: float}|null */
    final public function coordinates(): ?array
    {
        if (! $this->has('latitude') || ! $this->has('longitude')) {
            return null;
        }

        return [
            'latitude' => (float) $this->validated('latitude'),
            'longitude' => (float) $this->validated('longitude'),
        ];
    }

    /** @return array<string, array<int, mixed>> */
    final protected function baseRules(): array
    {
        return [
            'page' => ['sometimes', 'integer', 'min:1'],
            'restaurantPage' => ['sometimes', 'integer', 'min:1'],
            'dishPage' => ['sometimes', 'integer', 'min:1'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:50'],
            'latitude' => ['sometimes', 'numeric', 'between:-90,90'],
            'longitude' => ['sometimes', 'numeric', 'between:-180,180'],
            'cuisineId' => ['sometimes', 'integer', 'min:1', 'exists:cuisine_types,id'],
            'categoryId' => ['sometimes', 'integer', 'min:1', 'exists:categories,id'],
            'filter' => ['sometimes', 'array:openNow,hasOffers'],
            'filter.openNow' => ['sometimes', 'boolean'],
            'filter.hasOffers' => ['sometimes', 'boolean'],
            'sort' => ['sometimes', 'string', 'in:relevance,rating,nearest,fastest'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:20'],
            'perCategory' => ['sometimes', 'integer', 'min:1', 'max:30'],
            'reviewsPage' => ['sometimes', 'integer', 'min:1'],
            'reviewsPerPage' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ];
    }
}
