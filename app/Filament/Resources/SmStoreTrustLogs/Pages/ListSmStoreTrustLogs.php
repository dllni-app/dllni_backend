<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreTrustLogs\Pages;

use App\Filament\Concerns\ListSmRecordsWithSupermarketHubLink;
use App\Filament\Resources\SmStoreTrustLogs\SmStoreTrustLogResource;
use Filament\Resources\Pages\ListRecords;

final class ListSmStoreTrustLogs extends ListRecords
{
    use ListSmRecordsWithSupermarketHubLink;

    protected static string $resource = SmStoreTrustLogResource::class;

    public function getSubheading(): ?string
    {
        return __('supermarket_admin.pages.trust_logs.list');
    }
}
