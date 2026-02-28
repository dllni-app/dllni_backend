<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Workers\Pages;

use App\Filament\CleaningAdmin\Resources\Workers\WorkerResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateWorker extends CreateRecord
{
    protected static string $resource = WorkerResource::class;
}
