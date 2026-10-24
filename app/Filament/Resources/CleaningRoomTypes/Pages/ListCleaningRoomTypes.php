<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningRoomTypes\Pages;

use App\Filament\Resources\CleaningRoomTypes\CleaningRoomTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningRoomTypes extends ListRecords
{
    protected static string $resource = CleaningRoomTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('إضافة غرفة')];
    }
}
