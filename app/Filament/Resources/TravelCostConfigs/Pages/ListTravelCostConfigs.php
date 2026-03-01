<?php

declare(strict_types=1);

namespace App\Filament\Resources\TravelCostConfigs\Pages;

use App\Filament\Resources\TravelCostConfigs\TravelCostConfigResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

final class ListTravelCostConfigs extends ListRecords
{
    protected static string $resource = TravelCostConfigResource::class;

    public function getSubheading(): ?string
    {
        return 'قواعد حساب تكاليف التنقل: سعر الكيلومتر، الحد الأدنى لرسوم التنقل، نقطة بدء احتساب المسافة (موقع العامل / عنوان المنزل / النظام تلقائياً).';
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make(),
        ];
    }
}
