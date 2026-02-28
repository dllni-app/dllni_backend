<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\EventBookings\Pages;

use App\Filament\CleaningAdmin\Resources\EventBookings\EventBookingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListEventBookings extends ListRecords
{
    protected static string $resource = EventBookingResource::class;

    public function getSubheading(): ?string
    {
        return 'عرض وإدارة حجوزات المناسبات: عشاء عائلي، عيد ميلاد، تجمع كبير، جنازة؛ نطاق الضيوف، حجم الفريق، الحالة والسعر.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
