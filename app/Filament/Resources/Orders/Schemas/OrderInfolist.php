<?php

declare(strict_types=1);

namespace App\Filament\Resources\Orders\Schemas;

use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;

final class OrderInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('بيانات الطلب')
                    ->schema([
                        TextEntry::make('order_number')->label('رقم الطلب'),
                        TextEntry::make('restaurant.name')->label('المطعم'),
                        TextEntry::make('user.name')->label('العميل'),
                        TextEntry::make('status')
                            ->label('الحالة')
                            ->formatStateUsing(function ($state): string {
                                $value = $state?->value ?? $state;

                                return $value ? __('restaurant_admin.enums.order_status.'.$value) : '—';
                            }),
                        TextEntry::make('order_type')->label('نوع الطلب')->formatStateUsing(fn ($state) => $state?->value ?? $state ?? '—'),
                        TextEntry::make('total_amount')->label('المجموع')->money('SAR'),
                        TextEntry::make('subtotal')->label('المجموع الفرعي')->money('SAR')->placeholder('—'),
                        TextEntry::make('discount_amount')->label('الخصم')->money('SAR')->placeholder('—'),
                        TextEntry::make('special_instructions')->label('تعليمات خاصة')->placeholder('—'),
                    ])
                    ->columns(3),
                Section::make('دورة حياة الطلب')
                    ->schema([
                        TextEntry::make('accepted_at')->label('وقت القبول')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('preparing_at')->label('وقت بدء التحضير')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('ready_for_pickup_at')->label('جاهز للاستلام')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('picked_up_at')->label('تم الاستلام')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('completed_at')->label('وقت الإكمال')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('cancelled_at')->label('وقت الإلغاء')->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('cancellation_reason')->label('سبب الإلغاء')->placeholder('—'),
                    ])
                    ->columns(2),
                Section::make('سياسة الإلغاء')
                    ->schema([
                        TextEntry::make('cancellationPolicy.name')->label('السياسة')->placeholder('—'),
                        TextEntry::make('cancellation_fee_amount')->label('رسوم الإلغاء')->money('SAR')->placeholder('—'),
                    ])
                    ->columns(2)
                    ->collapsible(),
            ]);
    }
}
