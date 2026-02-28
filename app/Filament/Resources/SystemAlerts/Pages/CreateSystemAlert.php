<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemAlerts\Pages;

use App\Filament\Resources\SystemAlerts\SystemAlertResource;
use Filament\Resources\Pages\CreateRecord;

final class CreateSystemAlert extends CreateRecord
{
    protected static string $resource = SystemAlertResource::class;
}
