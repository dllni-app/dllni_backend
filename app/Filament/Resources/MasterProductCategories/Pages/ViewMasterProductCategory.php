<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProductCategories\Pages;

use App\Filament\Resources\MasterProductCategories\MasterProductCategoryResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewMasterProductCategory extends ViewRecord
{
    protected static string $resource = MasterProductCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
