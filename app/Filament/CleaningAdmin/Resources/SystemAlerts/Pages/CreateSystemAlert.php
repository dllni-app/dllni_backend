<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\SystemAlerts\Pages;

use App\Filament\CleaningAdmin\Resources\SystemAlerts\SystemAlertResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateSystemAlert extends CreateRecord
{
    protected static string $resource = SystemAlertResource::class;
}
