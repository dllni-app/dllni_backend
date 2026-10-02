<?php

declare(strict_types=1);

namespace App\Filament\Resources\DeliveryDisputes\Schemas;

use App\Filament\Resources\DeliveryOrders\DeliveryOrderResource;
use App\Models\Dispute;
use Filament\Infolists\Components\RepeatableEntry;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

final class DeliveryDisputeInfolist
{
    public static function configure(Schema $schema):Schema
    {
        return $schema->components([
            Section::make('بيانات النزاع')->schema([
                TextEntry::make('ticket_number')->label('رقم النزاع')->copyable(),
                TextEntry::make('category')->label('الفئة')->badge()->formatStateUsing(fn($state):string=>$state?->label()??'—'),
                TextEntry::make('status')->label('الحالة')->badge()->formatStateUsing(fn($state):string=>$state?->label()??'—'),
                TextEntry::make('resolution')->label('القرار')->badge()->placeholder('—')->formatStateUsing(fn($state):string=>$state?->label()??'—'),
                TextEntry::make('description')->label('الوصف')->columnSpanFull(),
                TextEntry::make('created_at')->label('تاريخ الفتح')->dateTime('Y-m-d H:i'),
                TextEntry::make('updated_at')->label('آخر تحديث')->dateTime('Y-m-d H:i'),
            ])->columns(3),
            Section::make('طلب التوصيل')->schema([
                TextEntry::make('booking.order_number')->label('رقم الطلب')->copyable()
                    ->url(fn(Dispute $record):?string=>$record->booking?DeliveryOrderResource::getUrl('view',['record'=>$record->booking]):null),
                TextEntry::make('booking.status')->label('حالة التوصيل')->badge()
                    ->formatStateUsing(fn(?string $state):string=>$state?__('delivery_company.orders.enums.status.'.$state):'—'),
                TextEntry::make('booking.company.name')->label('شركة التوصيل')->placeholder('—'),
                TextEntry::make('booking.driver.first_name')->label('المندوب')->placeholder('—'),
                TextEntry::make('booking.driver.trust_score')->label('ثقة المندوب')->placeholder('—'),
                TextEntry::make('booking.customer_name')->label('العميل')->placeholder('—'),
                TextEntry::make('booking.customer_phone')->label('هاتف العميل')->copyable()->placeholder('—'),
                TextEntry::make('booking.pickup_address')->label('الاستلام')->placeholder('—')->columnSpanFull(),
                TextEntry::make('booking.dropoff_address')->label('التسليم')->placeholder('—')->columnSpanFull(),
            ])->columns(3),
            Section::make('المحادثة')->schema([
                RepeatableEntry::make('messages')->label('')
                    ->schema([
                        TextEntry::make('sender.name')->label('المرسل')->placeholder('—'),
                        TextEntry::make('body')->label('الرسالة')->columnSpanFull(),
                        TextEntry::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i'),
                    ])->columns(2),
            ])->visible(fn(Dispute $record):bool=>$record->messages()->exists()),
            Section::make('أثر النزاع على ثقة المندوب')->schema([
                RepeatableEntry::make('trustLogs')->label('')
                    ->schema([
                        TextEntry::make('reason')->label('السبب'),
                        TextEntry::make('score_delta')->label('التغيير'),
                        TextEntry::make('score_after')->label('النقاط بعد التغيير'),
                        TextEntry::make('created_at')->label('الوقت')->dateTime('Y-m-d H:i'),
                    ])->columns(4),
            ])->visible(fn(Dispute $record):bool=>$record->trustLogs()->exists()),
        ]);
    }
}
