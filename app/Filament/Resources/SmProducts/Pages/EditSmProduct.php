<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmProducts\Pages;

use App\Filament\Resources\SmProducts\SmProductResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditSmProduct extends EditRecord
{
    protected static string $resource = SmProductResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
