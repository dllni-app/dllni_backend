<?php

declare(strict_types=1);

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

final class ListUsers extends ListRecords
{
    protected static string $resource = UserResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'مدراء النظام';
    }

    public function getSubheading(): ?string
    {
        return 'إدارة حسابات مدراء النظام وتحديد الدور المناسب لكل حساب.';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()->label('إضافة مدير نظام'),
        ];
    }
}
