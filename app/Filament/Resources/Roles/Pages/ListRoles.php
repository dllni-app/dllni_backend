<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListRoles extends ListRecords
{
    protected static string $resource = RoleResource::class;

    public function getSubheading(): ?string
    {
        return 'إدارة الأدوار والصلاحيات: تعريف من يمكنه ماذا في لوحة التحكم (مشرف، دعم، محاسب، إلخ).';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
