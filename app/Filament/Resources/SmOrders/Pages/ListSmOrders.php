<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmOrders\Pages;

use App\Filament\Resources\SmOrders\SmOrderResource;
use Filament\Resources\Pages\ListRecords;

final class ListSmOrders extends ListRecords
{
    protected static string $resource = SmOrderResource::class;

    public function getSubheading(): ?string
    {
        return __('supermarket_admin.pages.orders.list');
    }
}
