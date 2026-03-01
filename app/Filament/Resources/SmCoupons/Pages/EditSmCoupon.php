<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmCoupons\Pages;

use App\Filament\Resources\SmCoupons\SmCouponResource;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditSmCoupon extends EditRecord
{
    protected static string $resource = SmCouponResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
        ];
    }
}
