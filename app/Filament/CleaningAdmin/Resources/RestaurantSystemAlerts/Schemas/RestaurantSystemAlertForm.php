<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\RestaurantSystemAlerts\Schemas;

use App\Enums\SystemAlertStatus;
use Filament\Forms\Components\Select;
use Filament\Schemas\Schema;

final class RestaurantSystemAlertForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Select::make('status')
                    ->label('الحالة')
                    ->options([
                        SystemAlertStatus::New->value => SystemAlertStatus::New->label(),
                        SystemAlertStatus::Acknowledged->value => SystemAlertStatus::Acknowledged->label(),
                        SystemAlertStatus::Resolved->value => SystemAlertStatus::Resolved->label(),
                    ])
                    ->required(),
            ]);
    }
}
