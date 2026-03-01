<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemAlerts\Pages;

use App\Filament\Resources\SystemAlerts\SystemAlertResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditSystemAlert extends EditRecord
{
    protected static string $resource = SystemAlertResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
