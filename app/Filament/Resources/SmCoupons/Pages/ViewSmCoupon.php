<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmCoupons\Pages;

use App\Filament\Resources\SmCoupons\SmCouponResource;
use Filament\Actions\EditAction;
use Filament\Resources\Pages\ViewRecord;

final class ViewSmCoupon extends ViewRecord
{
    protected static string $resource = SmCouponResource::class;

    protected function getHeaderActions(): array
    {
        return [
            EditAction::make(),
        ];
    }
}
