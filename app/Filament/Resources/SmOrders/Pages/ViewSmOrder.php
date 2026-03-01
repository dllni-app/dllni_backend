<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOrders\Pages;

use App\Filament\Resources\SmOrders\SmOrderResource;
use Filament\Resources\Pages\ViewRecord;

final class ViewSmOrder extends ViewRecord
{
    protected static string $resource = SmOrderResource::class;
}
