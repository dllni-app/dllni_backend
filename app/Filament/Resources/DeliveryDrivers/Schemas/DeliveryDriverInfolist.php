<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryDrivers\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Delivery\Models\DeliveryDriver;

final class DeliveryDriverInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('بيانات المندوب')
                ->schema([
                    TextEntry::make('first_name')->label('الاسم'),
                    TextEntry::make('phone')->label('الهاتف')->copyable()->placeholder('—'),
                    TextEntry::make('user.email')->label('البريد')->placeholder('—'),
                    TextEntry::make('company.name')->label('شركة التوصيل')->placeholder('—'),
                    TextEntry::make('vehicle_type')->label('نوع المركبة')->placeholder('—'),
                    TextEntry::make('plate_number')->label('رقم اللوحة')->placeholder('—'),
                ])
                ->columns(3),

            Section::make('الحالة والثقة')
                ->schema([
                    TextEntry::make('availability_status')->label('حالة التوفر')->badge(),
                    TextEntry::make('is_active')->label('نشط')->formatStateUsing(fn ($state): string => $state ? 'نعم' : 'لا'),
                    TextEntry::make('is_suspended')->label('موقوف')->formatStateUsing(fn ($state): string => $state ? 'نعم' : 'لا'),
                    TextEntry::make('suspension_reason')->label('سبب الإيقاف')->placeholder('—'),
                    TextEntry::make('suspended_until')->label('الإيقاف حتى')->dateTime('Y-m-d H:i')->placeholder('—'),
                    TextEntry::make('trust_score')->label('نقاط الثقة'),
                    TextEntry::make('open_disputes_count')->label('النزاعات المفتوحة'),
                    TextEntry::make('orders_count')->label('إجمالي طلبات التوصيل')->placeholder('0'),
                    TextEntry::make('last_seen_at')->label('آخر ظهور')->dateTime('Y-m-d H:i')->placeholder('—'),
                ])
                ->columns(3),

            Section::make('آخر موقع')
                ->schema([
                    TextEntry::make('latestLocation.latitude')->label('خط العرض')->copyable()->placeholder('—'),
                    TextEntry::make('latestLocation.longitude')->label('خط الطول')->copyable()->placeholder('—'),
                    TextEntry::make('latestLocation.recorded_at')->label('وقت التسجيل')->dateTime('Y-m-d H:i')->placeholder('—'),
                ])
                ->columns(3)
                ->visible(fn (DeliveryDriver $record): bool => $record->latestLocation !== null),

            Section::make('سجل الثقة')
                ->schema([
                    RepeatableEntry::make('trustLogs')
                        ->label('')
                        ->schema([
                            TextEntry::make('reason')->label('السبب'),
                            TextEntry::make('score_delta')->label('التغيير'),
                            TextEntry::make('score_after')->label('الرصيد بعد التغيير'),
                            TextEntry::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i'),
                        ])
                        ->columns(4),
                ])
                ->visible(fn (DeliveryDriver $record): bool => $record->trustLogs()->exists()),

            Section::make('آخر طلبات التوصيل')
                ->schema([
                    RepeatableEntry::make('orders')
                        ->label('')
                        ->schema([
                            TextEntry::make('order_number')->label('الطلب'),
                            TextEntry::make('status')->label('الحالة')->badge(),
                            TextEntry::make('customer_name')->label('العميل'),
                            TextEntry::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i'),
                        ])
                        ->columns(4),
                ])
                ->visible(fn (DeliveryDriver $record): bool => $record->orders()->exists()),
        ]);
    }
}
