<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

use Illuminate\Validation\Rule;

final class RestaurantV2SearchRequest extends RestaurantV2BrowseRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return array_merge($this->baseRules(), [
            'q' => ['required', 'string', 'min:2', 'max:100'],
            'scope' => ['sometimes', 'string', 'in:all,restaurants,dishes'],
            'restaurantId' => ['sometimes', 'integer', 'min:1', Rule::prohibitedIf(fn (): bool => $this->input('scope', 'all') !== 'dishes')],
        ]);
    }

    public function searchText(): string
    {
        return mb_trim((string) $this->validated('q'));
    }

    public function scope(): string
    {
        return (string) $this->validated('scope', 'all');
    }

    public function restaurantId(): ?int
    {
        return $this->validated('restaurantId') === null ? null : (int) $this->validated('restaurantId');
    }

    public function restaurantPage(): int
    {
        return (int) $this->integer('restaurantPage', $this->pageNumber());
    }

    public function dishPage(): int
    {
        return (int) $this->integer('dishPage', $this->pageNumber());
    }
}
