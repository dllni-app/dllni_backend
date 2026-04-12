<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStores\Pages;

use App\Filament\Concerns\ListSmRecordsWithSupermarketHubLink;
use App\Filament\Resources\SmStores\SmStoreResource;
use Filament\Resources\Pages\ListRecords;

final class ListSmStores extends ListRecords
{
    use ListSmRecordsWithSupermarketHubLink;

    protected static string $resource = SmStoreResource::class;

    public function getSubheading(): ?string
    {
        return __('supermarket_admin.pages.stores.list');
    }
}
