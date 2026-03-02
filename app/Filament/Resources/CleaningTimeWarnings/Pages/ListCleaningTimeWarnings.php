<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningTimeWarnings\Pages;

use App\Filament\Resources\CleaningTimeWarnings\CleaningTimeWarningResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningTimeWarnings extends ListRecords
{
    protected static string $resource = CleaningTimeWarningResource::class;

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.pages.time_warnings.list');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
