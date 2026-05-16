<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupermarketOwners\Pages;

use App\Enums\UserModuleType;
use App\Filament\Resources\SupermarketOwners\SupermarketOwnerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditSupermarketOwner extends EditRecord
{
    protected static string $resource = SupermarketOwnerResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        $data['module_type'] = UserModuleType::SupermarketSeller->value;

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
