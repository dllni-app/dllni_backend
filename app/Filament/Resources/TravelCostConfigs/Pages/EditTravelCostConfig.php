<?php

declare(strict_types=1);

namespace App\Filament\Resources\TravelCostConfigs\Pages;

use App\Filament\Resources\TravelCostConfigs\TravelCostConfigResource;
use Filament\Actions\DeleteAction;
use Filament\Actions\ViewAction;
use Filament\Resources\Pages\EditRecord;

final class EditTravelCostConfig extends EditRecord
{
    protected static string $resource = TravelCostConfigResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ViewAction::make(),
            DeleteAction::make(),
        ];
    }
}
