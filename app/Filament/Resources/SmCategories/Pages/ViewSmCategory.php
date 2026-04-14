<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmCategories\Pages;

use App\Filament\Resources\SmCategories\SmCategoryResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewSmCategory extends ViewRecord
{
    protected static string $resource = SmCategoryResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
