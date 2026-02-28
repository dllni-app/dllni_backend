<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Disputes\Schemas;

use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class DisputeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('التذكرة')
                    ->schema([
                        TextEntry::make('ticket_number')->label('رقم التذكرة'),
                        TextEntry::make('description')->label('تفاصيل المشكلة'),
                        TextEntry::make('category')->label('التصنيف')->badge()->formatStateUsing(fn ($state) => $state?->label()),
                        TextEntry::make('status')->label('الحالة')->badge()->formatStateUsing(fn ($state) => $state?->label()),
                        TextEntry::make('resolution')->label('القرار')->badge()->placeholder('-')->formatStateUsing(fn ($state) => $state?->label()),
                        TextEntry::make('worker_earnings_frozen')->label('مستحقات العامل مجمدة')->formatStateUsing(fn ($state) => $state ? 'نعم' : 'لا'),
                    ])
                    ->columns(2),
                Section::make('الحجز')
                    ->schema([
                        TextEntry::make('booking.booking_number')->label('رقم الحجز')->placeholder('-'),
                        TextEntry::make('booking.customer.name')->label('العميل')->placeholder('-'),
                        TextEntry::make('booking.worker.first_name')->label('العامل')->placeholder('-'),
                    ])
                    ->columns(3)
                    ->visible(fn ($record) => $record->booking),
                Section::make('سجل المحادثات')
                    ->schema([
                        RepeatableEntry::make('messages')
                            ->label('')
                            ->schema([
                                TextEntry::make('sender_id')->label('المرسل')->formatStateUsing(fn ($state, $record) => $record->sender?->name ?? (string) $state),
                                TextEntry::make('body')->label('النص'),
                                TextEntry::make('created_at')->label('التاريخ')->dateTime('Y-m-d H:i'),
                            ])
                            ->columns(3),
                    ]),
            ]);
    }
}
