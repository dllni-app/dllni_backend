<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserRestaurantMenuSectionsRequest extends FormRequest
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
            'itemsPerSection' => ['sometimes', 'integer', 'min:1', 'max:30'],
        ];
    }

    public function getItemsPerSection(): int
    {
        return (int) $this->integer('itemsPerSection', 10);
    }
}
