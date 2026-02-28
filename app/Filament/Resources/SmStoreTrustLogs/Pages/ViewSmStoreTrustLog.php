<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreTrustLogs\Pages;

use App\Filament\Resources\SmStoreTrustLogs\SmStoreTrustLogResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewSmStoreTrustLog extends ViewRecord
{
    protected static string $resource = SmStoreTrustLogResource::class;
}
