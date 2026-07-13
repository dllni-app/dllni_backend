<?php

declare(strict_types=1);

namespace App\Filament\Resources\Roles\Pages;

use App\Filament\Resources\Roles\RoleResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;
use Filament\Support\Enums\Width;

final class ViewRole extends ViewRecord
{
    protected static string $resource = RoleResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getTitle(): string
    {
        return 'عرض الدور';
    }

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make()->label('تعديل'),
        ];
    }
}
