<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOrderDisputes\Pages;

use App\Filament\Resources\SmOrderDisputes\SmOrderDisputeResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditSmOrderDispute extends EditRecord
{
    protected static string $resource = SmOrderDisputeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
