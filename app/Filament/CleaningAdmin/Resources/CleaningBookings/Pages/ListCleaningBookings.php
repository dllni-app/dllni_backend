<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningBookings\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningBookings\CleaningBookingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningBookings extends ListRecords
{
    protected static string $resource = CleaningBookingResource::class;

    public function getSubheading(): ?string
    {
        return 'عرض وإدارة جميع حجوزات التنظيف: رقم الحجز، العميل، العامل، التاريخ والوقت، الحالة، السعر الإجمالي، وتعيين عامل أو إلغاء.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
