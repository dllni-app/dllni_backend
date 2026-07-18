<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningHomeTypes\Pages;

use App\Filament\Resources\CleaningHomeTypes\CleaningHomeTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

final class ListCleaningHomeTypes extends ListRecords
{
    protected static string $resource = CleaningHomeTypeResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'أنواع واجهة التنظيف';
    }

    public function getSubheading(): ?string
    {
        return 'إدارة أسماء وصور وترتيب أنواع العقارات والمناسبات الظاهرة في تطبيق المستخدم.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('إضافة نوع جديد'),
        ];
    }
}
