<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStores\Pages;

use App\Filament\Resources\SmStores\SmStoreResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditSmStore extends EditRecord
{
    protected static string $resource = SmStoreResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
