<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\ServiceAddons\Pages;

use App\Filament\CleaningAdmin\Resources\ServiceAddons\ServiceAddonResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListServiceAddons extends ListRecords
{
    protected static string $resource = ServiceAddonResource::class;

    public function getSubheading(): ?string
    {
        return 'إدارة إضافات الخدمة: الاسم، النوع (ثابت أو نسبة)، السعر، وربطها بخدمات التنظيف.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
