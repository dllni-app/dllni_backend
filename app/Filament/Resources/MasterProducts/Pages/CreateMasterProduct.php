<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProducts\Pages;

use App\Filament\Resources\MasterProducts\MasterProductResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateMasterProduct extends CreateRecord
{
    protected static string $resource = MasterProductResource::class;
}
