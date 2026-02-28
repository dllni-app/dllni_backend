<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningTimeWarnings\Pages;

use App\Filament\Resources\CleaningTimeWarnings\CleaningTimeWarningResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningTimeWarnings extends ListRecords
{
    protected static string $resource = CleaningTimeWarningResource::class;

    public function getSubheading(): ?string
    {
        return 'سجل تنبيهات انتهاء الوقت: رقم الحجز، نوع الحجز (تنظيف/مناسبة)، وقت الإرسال، رد العميل (تمديد/التزام/إنهاء مبكر)، رد العامل.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
