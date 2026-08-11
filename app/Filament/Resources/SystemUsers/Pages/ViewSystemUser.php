<?php

declare(strict_types=1);

namespace App\Filament\Resources\SystemUsers\Pages;

use App\Filament\Resources\SystemUsers\SystemUserResource;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Contracts\Support\Htmlable;

final class ViewSystemUser extends ViewRecord
{
    protected static string $resource = SystemUserResource::class;

    public function getTitle(): string|Htmlable
    {
        return 'تفاصيل المستخدم';
    }
}
