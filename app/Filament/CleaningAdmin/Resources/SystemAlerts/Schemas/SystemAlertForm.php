<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\SystemAlerts\Schemas;

use App\Enums\AlertSeverity;
use App\Enums\AlertType;
use App\Enums\SystemAlertStatus;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

final class SystemAlertForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextInput::make('booking_id')->label('رقم الحجز')->numeric(),
                TextInput::make('booking_type')->label('نوع الحجز')->required(),
                Select::make('alert_type')
                    ->label('نوع التنبيه')
                    ->options(collect(AlertType::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all())
                    ->required(),
                Select::make('severity')
                    ->label('الخطورة')
                    ->options(collect(AlertSeverity::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all())
                    ->required(),
                Select::make('status')
                    ->label('الحالة')
                    ->options(collect(SystemAlertStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all())
                    ->required(),
                KeyValue::make('payload')->label('بيانات إضافية'),
            ]);
    }
}
