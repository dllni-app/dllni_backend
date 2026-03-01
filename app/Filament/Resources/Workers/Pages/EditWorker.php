<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Pages;

use App\Filament\Resources\Workers\WorkerResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditWorker extends EditRecord
{
    protected static string $resource = WorkerResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
