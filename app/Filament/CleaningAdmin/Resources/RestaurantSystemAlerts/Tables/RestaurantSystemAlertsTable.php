<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\RestaurantSystemAlerts\Tables;

use App\Enums\SystemAlertStatus;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class RestaurantSystemAlertsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('alert_type')->label('نوع التنبيه')->badge()->formatStateUsing(fn ($state) => $state?->label() ?? $state),
                TextColumn::make('severity')->label('الخطورة')->badge()->formatStateUsing(fn ($state) => $state?->label() ?? $state),
                TextColumn::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => $state?->label() ?? $state),
                TextColumn::make('booking.order_number')->label('رقم الطلب')->placeholder('-'),
                TextColumn::make('created_at')->label('تاريخ الإنشاء')->since()->sortable(),
            ])
            ->recordActions([
                Action::make('contact')
                    ->label('اتصال')
                    ->action(function ($record): void {
                        Notification::make()->title('تم تسجيل إجراء الاتصال')->success()->send();
                    }),
                Action::make('resolve')
                    ->label('حل التنبيه')
                    ->color('success')
                    ->action(fn ($record) => $record->update(['status' => SystemAlertStatus::Resolved->value])),
                Action::make('safety_confirmed')
                    ->label('تأكيد السلامة')
                    ->color('warning')
                    ->action(fn ($record) => $record->update(['status' => SystemAlertStatus::Acknowledged->value])),
                ViewAction::make(),
                EditAction::make(),
            ]);
    }
}
