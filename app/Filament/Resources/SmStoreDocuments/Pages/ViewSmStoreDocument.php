<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreDocuments\Pages;

use App\Filament\Resources\SmStoreDocuments\SmStoreDocumentResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewSmStoreDocument extends ViewRecord
{
    protected static string $resource = SmStoreDocumentResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
