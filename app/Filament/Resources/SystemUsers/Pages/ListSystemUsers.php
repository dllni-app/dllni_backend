<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemUsers\Pages;

use App\Filament\Resources\SystemUsers\SystemUserResource;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Contracts\Support\Htmlable;

final class ListSystemUsers extends ListRecords
{
    protected static string $resource = SystemUserResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'مستخدمو النظام';
    }

    public function getSubheading(): ?string
    {
        return 'عرض حسابات العملاء والعاملين والبائعين والسائقين والتحكم بحالة الوصول إلى النظام.';
    }
}
