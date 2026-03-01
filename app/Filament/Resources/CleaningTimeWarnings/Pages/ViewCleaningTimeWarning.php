<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningTimeWarnings\Pages;

use App\Filament\Resources\CleaningTimeWarnings\CleaningTimeWarningResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewCleaningTimeWarning extends ViewRecord
{
    protected static string $resource = CleaningTimeWarningResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
