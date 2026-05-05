<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProductCategories\Pages;

use App\Filament\Resources\MasterProductCategories\MasterProductCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditMasterProductCategories extends EditRecord
{
    protected static string $resource = MasterProductCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
