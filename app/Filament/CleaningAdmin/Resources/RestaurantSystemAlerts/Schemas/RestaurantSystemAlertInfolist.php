<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\RestaurantSystemAlerts\Schemas;

use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class RestaurantSystemAlertInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('التنبيه')
                    ->schema([
                        TextEntry::make('alert_type')->label('نوع التنبيه')->formatStateUsing(fn ($state) => $state?->label() ?? $state),
                        TextEntry::make('severity')->label('الخطورة')->formatStateUsing(fn ($state) => $state?->label() ?? $state),
                        TextEntry::make('status')->label('الحالة')->formatStateUsing(fn ($state) => $state?->label() ?? $state),
                        TextEntry::make('booking.order_number')->label('رقم الطلب'),
                        TextEntry::make('created_at')->label('تاريخ الإنشاء')->dateTime('Y-m-d H:i'),
                    ])
                    ->columns(2),
            ]);
    }
}
