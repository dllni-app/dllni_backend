<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Disputes\Pages;

use App\Filament\CleaningAdmin\Resources\Disputes\DisputeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListDisputes extends ListRecords
{
    protected static string $resource = DisputeResource::class;

    public function getSubheading(): ?string
    {
        return 'عرض النزاعات والشكاوى: رقم النزاع، رقم الحجز، العميل، العامل، السبب، الحالة، وقت الفتح؛ عرض الرد والحل (استرداد جزئي، خصم من العامل، إغلاق).';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
