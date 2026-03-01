<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStoreDailyStats\Pages;

use App\Filament\Resources\SmStoreDailyStats\SmStoreDailyStatResource;
use Filament\Resources\Pages\ListRecords;

final class ListSmStoreDailyStats extends ListRecords
{
    protected static string $resource = SmStoreDailyStatResource::class;
}
