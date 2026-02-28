<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreDocuments\Pages;

use App\Filament\Resources\SmStoreDocuments\SmStoreDocumentResource;
use Filament\Resources\Pages\ListRecords;

final class ListSmStoreDocuments extends ListRecords
{
    protected static string $resource = SmStoreDocumentResource::class;
}
