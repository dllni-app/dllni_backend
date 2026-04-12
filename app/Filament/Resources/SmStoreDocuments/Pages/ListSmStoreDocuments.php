<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreDocuments\Pages;

use App\Filament\Concerns\ListSmRecordsWithSupermarketHubLink;
use App\Filament\Resources\SmStoreDocuments\SmStoreDocumentResource;
use Filament\Resources\Pages\ListRecords;

final class ListSmStoreDocuments extends ListRecords
{
    use ListSmRecordsWithSupermarketHubLink;

    protected static string $resource = SmStoreDocumentResource::class;

    public function getSubheading(): ?string
    {
        return __('supermarket_admin.pages.store_documents.list');
    }
}
