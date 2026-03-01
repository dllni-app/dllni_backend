<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmCoupons\Pages;

use App\Filament\Resources\SmCoupons\SmCouponResource;
use Filament\Resources\Pages\ListRecords;

final class ListSmCoupons extends ListRecords
{
    protected static string $resource = SmCouponResource::class;
}
