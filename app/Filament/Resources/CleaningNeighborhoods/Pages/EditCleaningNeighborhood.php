<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningNeighborhoods\Pages;

use App\Filament\Resources\CleaningNeighborhoods\CleaningNeighborhoodResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

final class EditCleaningNeighborhood extends EditRecord
{
    protected static string $resource = CleaningNeighborhoodResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
