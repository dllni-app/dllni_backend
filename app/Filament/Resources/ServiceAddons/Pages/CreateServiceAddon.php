<?php

declare(strict_types=1);

namespace App\Filament\Resources\ServiceAddons\Pages;

use App\Filament\Resources\ServiceAddons\ServiceAddonResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateServiceAddon extends CreateRecord
{
    protected static string $resource = ServiceAddonResource::class;
}
