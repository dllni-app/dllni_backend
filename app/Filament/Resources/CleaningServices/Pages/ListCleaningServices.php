<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningServices\Pages;

use App\Filament\Resources\CleaningServices\CleaningServiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningServices extends ListRecords
{
    protected static string $resource = CleaningServiceResource::class;

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.pages.cleaning_services.list');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
