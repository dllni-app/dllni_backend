<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

final class RestaurantV2HomeRequest extends RestaurantV2BrowseRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        $rules = $this->baseRules();
        $rules['perPage'] = ['sometimes', 'integer', 'min:1', 'max:20'];
        $rules['includeUpcoming'] = ['sometimes', 'boolean'];
        unset($rules['limit']);

        return $rules;
    }
}
