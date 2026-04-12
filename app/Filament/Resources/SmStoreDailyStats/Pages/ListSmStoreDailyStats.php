<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreDailyStats\Pages;

use App\Filament\Concerns\ListSmRecordsWithSupermarketHubLink;
use App\Filament\Resources\SmStoreDailyStats\SmStoreDailyStatResource;
use Filament\Resources\Pages\ListRecords;

final class ListSmStoreDailyStats extends ListRecords
{
    use ListSmRecordsWithSupermarketHubLink;

    protected static string $resource = SmStoreDailyStatResource::class;

    public function getSubheading(): ?string
    {
        return __('supermarket_admin.pages.daily_stats.list');
    }
}
