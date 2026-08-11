<?php

declare(strict_types=1);

namespace App\Filament\Resources\PlatformCoupons\Pages;

use App\Filament\Resources\PlatformCoupons\PlatformCouponResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListPlatformCoupons extends ListRecords
{
    protected static string $resource = PlatformCouponResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('إضافة كوبون')];
    }
}
