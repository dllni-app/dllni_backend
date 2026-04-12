<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmCoupons\Pages;

use App\Filament\Concerns\ListSmRecordsWithSupermarketHubLink;
use App\Filament\Resources\SmCoupons\SmCouponResource;
use Filament\Resources\Pages\ListRecords;

final class ListSmCoupons extends ListRecords
{
    use ListSmRecordsWithSupermarketHubLink;

    protected static string $resource = SmCouponResource::class;

    public function getSubheading(): ?string
    {
        return __('supermarket_admin.pages.coupons.list');
    }
}
