<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantOwners\Pages;

use App\Enums\UserModuleType;
use App\Filament\Resources\RestaurantOwners\RestaurantOwnerResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateRestaurantOwner extends CreateRecord
{
    protected static string $resource = RestaurantOwnerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['module_type'] = UserModuleType::RestaurantSeller->value;

        return $data;
    }
}
