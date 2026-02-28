<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmProducts\Pages;

use App\Filament\Resources\SmProducts\SmProductResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewSmProduct extends ViewRecord
{
    protected static string $resource = SmProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
