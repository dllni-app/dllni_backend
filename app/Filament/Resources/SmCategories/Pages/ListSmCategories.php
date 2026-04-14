<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmCategories\Pages;

use App\Filament\Pages\SupermarketSectionHub;
use App\Filament\Resources\SmCategories\SmCategoryResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListSmCategories extends ListRecords
{
    protected static string $resource = SmCategoryResource::class;

    public function getSubheading(): ?string
    {
        return __('supermarket_admin.pages.categories.list');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
            Action::make('supermarketHub')
                ->label(__('supermarket_admin.hub.back_to_hub'))
                ->icon('heroicon-o-arrow-left')
                ->url(SupermarketSectionHub::getUrl()),
        ];
    }
}
