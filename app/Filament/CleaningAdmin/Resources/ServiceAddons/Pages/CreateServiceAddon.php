<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\ServiceAddons\Pages;

use App\Filament\CleaningAdmin\Resources\ServiceAddons\ServiceAddonResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateServiceAddon extends CreateRecord
{
    protected static string $resource = ServiceAddonResource::class;
}
