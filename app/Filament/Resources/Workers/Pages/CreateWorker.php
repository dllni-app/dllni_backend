<?php

declare(strict_types=1);

namespace App\Filament\Resources\Workers\Pages;

use App\Filament\Resources\Workers\WorkerResource;
use App\Filament\Resources\Workers\Pages\Concerns\SyncsWorkerLinkedUser;
use Filament\Resources\Pages\CreateRecord;

final class CreateWorker extends CreateRecord
{
    use SyncsWorkerLinkedUser;

    protected static string $resource = WorkerResource::class;

    protected function afterCreate(): void
    {
        $this->syncLinkedUserAccount();
    }
}
