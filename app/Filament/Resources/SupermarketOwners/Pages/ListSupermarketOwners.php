<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupermarketOwners\Pages;

use App\Filament\Resources\SupermarketOwners\SupermarketOwnerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSupermarketOwners extends ListRecords
{
    protected static string $resource = SupermarketOwnerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}

