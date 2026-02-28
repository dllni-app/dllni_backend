<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningTimeWarnings\Pages;

use App\Filament\Resources\CleaningTimeWarnings\CleaningTimeWarningResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditCleaningTimeWarning extends EditRecord
{
    protected static string $resource = CleaningTimeWarningResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
