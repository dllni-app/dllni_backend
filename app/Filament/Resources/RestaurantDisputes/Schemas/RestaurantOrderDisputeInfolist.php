<?php

declare(strict_types=1);

namespace App\Filament\Resources\RestaurantDisputes\Schemas;

use BackedEnum;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Modules\Resturants\Enums\RestaurantDisputeStatus;

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
                            ->formatStateUsing(function ($state): string {
                                $value = $state instanceof RestaurantDisputeStatus ? $state->value : $state;

                                return __('restaurant_admin.enums.dispute_status.'.($value ?? 'open'));
                            }),
                        TextEntry::make('resolution_type')
                            ->label('القرار')
                            ->formatStateUsing(function ($state): string {
                                $value = $state instanceof BackedEnum ? $state->value : $state;

                                return $value ? __('restaurant_admin.enums.resolution_type.'.$value) : '-';
                            }),
                        TextEntry::make('payout_hold_status')
                            ->label('حالة التجميد')
                            ->formatStateUsing(function ($state): string {
                                $value = $state instanceof BackedEnum ? $state->value : $state;

                                return __('restaurant_admin.enums.payout_hold_status.'.($value ?? 'held'));
                            }),
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
