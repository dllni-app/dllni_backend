<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningHomeTypes\Pages;

use App\Filament\Resources\CleaningHomeTypes\CleaningHomeTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditCleaningHomeType extends EditRecord
{
    protected static string $resource = CleaningHomeTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->label('حذف')
                ->requiresConfirmation(),
        ];
    }
}
