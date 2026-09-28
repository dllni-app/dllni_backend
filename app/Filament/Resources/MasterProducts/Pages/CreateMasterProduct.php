<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProducts\Pages;

use App\Filament\Resources\MasterProducts\MasterProductResource;
use App\Filament\Resources\MasterProducts\Pages\Concerns\SyncsMasterProductImage;
use Filament\Resources\Pages\CreateRecord;

final class CreateMasterProduct extends CreateRecord
{
    use SyncsMasterProductImage;

    protected static string $resource = MasterProductResource::class;

    protected function afterCreate(): void
    {
        $this->syncMasterProductImageFromForm();
    }
}
