<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemAlerts\Pages;

use App\Filament\Resources\SystemAlerts\SystemAlertResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewSystemAlert extends ViewRecord
{
    protected static string $resource = SystemAlertResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
