<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Disputes\Pages;

use App\Filament\CleaningAdmin\Resources\Disputes\DisputeResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditDispute extends EditRecord
{
    protected static string $resource = DisputeResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
