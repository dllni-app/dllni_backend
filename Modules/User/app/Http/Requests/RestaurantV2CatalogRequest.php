<?php

declare(strict_types=1);

namespace Modules\User\Http\Requests;

final class RestaurantV2CatalogRequest extends RestaurantV2BrowseRequest
{
    /** @return array<string, array<int, mixed>> */
    public function rules(): array
    {
        return $this->baseRules();
    }
}
