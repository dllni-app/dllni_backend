<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantOwners\Pages;

use App\Enums\UserModuleType;
use App\Filament\Resources\RestaurantOwners\RestaurantOwnerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditRestaurantOwner extends EditRecord
{
    protected static string $resource = RestaurantOwnerResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['module_type'] = UserModuleType::RestaurantSeller->value;

        return $data;
    }

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
