<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupermarketOwners\Pages;

use App\Enums\UserModuleType;
use App\Filament\Resources\SupermarketOwners\SupermarketOwnerResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateSupermarketOwner extends CreateRecord
{
    protected static string $resource = SupermarketOwnerResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['module_type'] = UserModuleType::SupermarketSeller->value;

        return $data;
    }
}
