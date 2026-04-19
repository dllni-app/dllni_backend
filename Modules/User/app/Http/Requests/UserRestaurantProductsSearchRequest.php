<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserRestaurantProductsSearchRequest extends FormRequest
{
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
            'restaurantId' => ['sometimes', 'nullable', 'integer', 'exists:restaurants,id'],
            'categoryId' => ['required', 'integer', 'exists:categories,id'],
            'text' => ['sometimes', 'nullable', 'string', 'max:255'],
            'perPage' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'integer', 'min:1'],
        ];
    }

    public function getPerPage(): int
    {
        return (int) $this->integer('perPage', 20);
    }

    public function getPage(): int
    {
        return max(1, (int) $this->integer('page', 1));
    }

    public function getRestaurantId(): ?int
    {
        $restaurantId = $this->input('restaurantId');

        return $restaurantId === null ? null : (int) $restaurantId;
    }

    public function getCategoryId(): int
    {
        return (int) $this->input('categoryId');
    }

    public function getText(): ?string
    {
        $text = $this->input('text');

        if (! is_string($text)) {
            return null;
        }

        $text = mb_trim($text);

        return $text !== '' ? $text : null;
    }
}
