<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningAttendanceIncidents\Pages;

use App\Filament\Resources\CleaningAttendanceIncidents\CleaningAttendanceIncidentResource;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningAttendanceIncidents extends ListRecords
{
    protected static string $resource = CleaningAttendanceIncidentResource::class;

    public function getTitle(): string
    {
        return 'بلاغات تأخر وعدم توجه العمال';
    }
}
