<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use App\Filament\Pages\SupermarketSectionHub;
use Filament\Actions\Action;

trait ListSmRecordsWithSupermarketHubLink
{
    protected function getHeaderActions(): array
    {
        return [
            Action::make('supermarketHub')
                ->label(__('supermarket_admin.hub.back_to_hub'))
                ->icon('heroicon-o-arrow-left')
                ->url(SupermarketSectionHub::getUrl()),
        ];
    }
}
