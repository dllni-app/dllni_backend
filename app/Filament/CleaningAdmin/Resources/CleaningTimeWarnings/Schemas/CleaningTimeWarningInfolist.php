<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class CleaningTimeWarningInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                TextEntry::make('booking_type')->label('نوع الحجز')->formatStateUsing(fn (?string $state) => $state === 'cleaning' ? 'تنظيف' : ($state === 'event' ? 'مناسبة' : $state ?? '-')),
                TextEntry::make('booking_id')->label('رقم الحجز'),
                TextEntry::make('sent_at')->label('وقت الإرسال')->dateTime('Y-m-d H:i'),
                TextEntry::make('customer_response')->label('رد العميل')->badge()->placeholder('-')->formatStateUsing(fn ($state) => $state?->label()),
                TextEntry::make('worker_response')->label('رد العامل')->badge()->placeholder('-')->formatStateUsing(fn ($state) => $state?->label()),
                TextEntry::make('additional_minutes')->label('دقائق إضافية')->placeholder('-'),
                TextEntry::make('worker_reject_message')->label('رسالة رفض العامل')->placeholder('-'),
            ]);
    }
}
