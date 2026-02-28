<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningServices\Pages;

use App\Filament\CleaningAdmin\Resources\CleaningServices\CleaningServiceResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListCleaningServices extends ListRecords
{
    protected static string $resource = CleaningServiceResource::class;

    public function getSubheading(): ?string
    {
        return 'إدارة خدمات التنظيف: الاسم، الوصف، التسعير (سعر أساسي، حد أدنى للساعات)، والربط مع الإضافات.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
