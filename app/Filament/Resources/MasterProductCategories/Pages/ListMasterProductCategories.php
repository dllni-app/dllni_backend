<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProductCategories\Pages;

use App\Filament\Pages\SupermarketSectionHub;
use App\Filament\Resources\MasterProductCategories\MasterProductCategoryResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListMasterProductCategories extends ListRecords
{
    protected static string $resource = MasterProductCategoryResource::class;

    public function getSubheading(): ?string
    {
        return __('supermarket_admin.pages.master_product_categories.list');
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
