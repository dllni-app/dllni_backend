<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Pages;

use App\Filament\Resources\Workers\WorkerResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateWorker extends CreateRecord
{
    protected static string $resource = WorkerResource::class;
}
