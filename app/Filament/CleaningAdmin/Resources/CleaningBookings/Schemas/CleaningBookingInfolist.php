<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningBookings\Schemas;

use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class CleaningBookingInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('الحجز')
                    ->schema([
                        TextEntry::make('booking_number')->label('رقم الحجز'),
                        TextEntry::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => $state?->label()),
                        TextEntry::make('property_type')->label('نوع العقار'),
                        TextEntry::make('estimated_sqm')->label('المتر المربع التقديري'),
                        TextEntry::make('estimated_hours')->label('الساعات التقديرية'),
                        TextEntry::make('scheduled_date')->label('التاريخ')->date(),
                        TextEntry::make('scheduled_time')->label('الوقت'),
                    ])
                    ->columns(2),
                Section::make('التسعير')
                    ->schema([
                        TextEntry::make('base_price')->label('السعر الأساسي')->money('SAR'),
                        TextEntry::make('addons_total')->label('الإضافات')->money('SAR'),
                        TextEntry::make('travel_fee')->label('بدل الانتقال')->money('SAR'),
                        TextEntry::make('total_price')->label('المجموع')->money('SAR'),
                    ])
                    ->columns(2),
                Section::make('أوقات التنفيذ')
                    ->schema([
                        TextEntry::make('work_started_at')->label('بداية العمل')->dateTime('Y-m-d H:i')->placeholder('-'),
                        TextEntry::make('work_finished_at')->label('نهاية العمل')->dateTime('Y-m-d H:i')->placeholder('-'),
                        TextEntry::make('customer_confirmed_at')->label('تأكيد العميل')->dateTime('Y-m-d H:i')->placeholder('-'),
                    ])
                    ->columns(3),
                Section::make('الأطراف')
                    ->schema([
                        TextEntry::make('customer.name')->label('العميل'),
                        TextEntry::make('worker.first_name')->label('العامل')->placeholder('-'),
                    ])
                    ->columns(2),
                Section::make('النزاعات')
                    ->schema([
                        TextEntry::make('disputes_count')->counts('disputes')->label('عدد النزاعات'),
                    ])
                    ->visible(fn ($record) => $record->disputes()->count() > 0),
            ]);
    }
}
