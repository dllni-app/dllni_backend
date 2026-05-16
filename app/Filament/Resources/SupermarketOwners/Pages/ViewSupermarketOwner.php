<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupermarketOwners\Pages;

use App\Filament\Resources\SupermarketOwners\SupermarketOwnerResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewSupermarketOwner extends ViewRecord
{
    protected static string $resource = SupermarketOwnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}

