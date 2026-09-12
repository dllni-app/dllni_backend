<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningAttendanceIncidents\Pages;

use App\Filament\Resources\CleaningAttendanceIncidents\CleaningAttendanceIncidentResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewCleaningAttendanceIncident extends ViewRecord
{
    protected static string $resource = CleaningAttendanceIncidentResource::class;

    public function getTitle(): string
    {
        return 'تفاصيل بلاغ الحضور';
    }
}
