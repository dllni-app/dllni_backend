<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningNeighborhoods\Pages;

use App\Filament\Resources\CleaningNeighborhoods\CleaningNeighborhoodResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

final class ListCleaningNeighborhoods extends ListRecords
{
    protected static string $resource = CleaningNeighborhoodResource::class;

    public function getTitle(): string|Htmlable
    {
        return __('cleaning_admin.cleaning_neighborhoods.nav_label');
    }

    public function getSubheading(): ?string
    {
        return __('cleaning_admin.pages.cleaning_neighborhoods.list');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('cleaning_admin.cleaning_neighborhoods.actions.create')),
        ];
    }
}
