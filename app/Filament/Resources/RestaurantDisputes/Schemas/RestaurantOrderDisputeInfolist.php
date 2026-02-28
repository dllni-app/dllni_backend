<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantDisputes\Schemas;

use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class RestaurantOrderDisputeInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('بيانات النزاع')
                    ->schema([
                        TextEntry::make('ticket_number')->label('رقم التذكرة'),
                        TextEntry::make('order.order_number')->label('رقم الطلب'),
                        TextEntry::make('user.name')->label('تم الفتح بواسطة'),
                        TextEntry::make('status')
                            ->label('الحالة')
                            ->formatStateUsing(fn (?string $state): string => __('restaurant_admin.enums.dispute_status.'.($state ?? 'open'))),
                        TextEntry::make('resolution_type')
                            ->label('القرار')
                            ->formatStateUsing(fn (?string $state): string => $state ? __('restaurant_admin.enums.resolution_type.'.$state) : '-'),
                        TextEntry::make('payout_hold_status')
                            ->label('حالة التجميد')
                            ->formatStateUsing(fn (?string $state): string => __('restaurant_admin.enums.payout_hold_status.'.($state ?? 'held'))),
                        TextEntry::make('refund_amount')->label('مبلغ الاسترداد'),
                        TextEntry::make('deduction_amount')->label('مبلغ الخصم'),
                        TextEntry::make('resolvedBy.name')->label('تم الحل بواسطة')->placeholder('-'),
                        TextEntry::make('resolved_at')->label('تاريخ الحل')->dateTime('Y-m-d H:i')->placeholder('-'),
                        TextEntry::make('admin_note')->label('ملاحظة الأدمن')->placeholder('-'),
                    ])
                    ->columns(3),
            ]);
    }
}
