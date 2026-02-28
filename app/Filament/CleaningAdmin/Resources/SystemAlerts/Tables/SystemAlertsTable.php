<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\SystemAlerts\Tables;

use App\Enums\AlertSeverity;
use App\Enums\SystemAlertStatus;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class SystemAlertsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('alert_type')->label('نوع التنبيه')->badge()->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('severity')->label('الخطورة')->badge()->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => $state?->label()),
                TextColumn::make('booking_type')->label('نوع الحجز')->formatStateUsing(fn (?string $state) => $state === 'cleaning' ? 'تنظيف' : ($state === 'event' ? 'مناسبة' : $state ?? '-')),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->since()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->label('الحالة')->options(collect(SystemAlertStatus::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
                SelectFilter::make('severity')->label('الخطورة')->options(collect(AlertSeverity::cases())->mapWithKeys(fn ($c) => [$c->value => $c->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
