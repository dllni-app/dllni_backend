<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Workers\Pages;

use App\Filament\CleaningAdmin\Resources\Workers\WorkerResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListWorkers extends ListRecords
{
    protected static string $resource = WorkerResource::class;

    public function getSubheading(): ?string
    {
        return 'قائمة مقدمي الخدمة: الاسم، الصورة، نقاط الثقة، المهام المكتملة، متوسط التقييم، الحالة؛ عرض الملف وتعليق الحساب.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('إضافة عامل'),
        ];
    }
}
