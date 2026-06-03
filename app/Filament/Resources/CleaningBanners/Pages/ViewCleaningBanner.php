<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBanners\Pages;

use App\Filament\Resources\CleaningBanners\CleaningBannerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewCleaningBanner extends ViewRecord
{
    protected static string $resource = CleaningBannerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
            DeleteAction::make(),
        ];
    }
}
