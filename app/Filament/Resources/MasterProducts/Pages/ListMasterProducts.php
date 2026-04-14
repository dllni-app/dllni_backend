<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProducts\Pages;

use App\Filament\Pages\SupermarketSectionHub;
use App\Filament\Resources\MasterProducts\MasterProductResource;
use Filament\Actions\Action;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListMasterProducts extends ListRecords
{
    protected static string $resource = MasterProductResource::class;

    public function getSubheading(): ?string
    {
        return __('supermarket_admin.pages.master_products.list');
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
