<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmCategories\Pages;

use App\Filament\Resources\SmCategories\SmCategoryResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditSmCategory extends EditRecord
{
    protected static string $resource = SmCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
