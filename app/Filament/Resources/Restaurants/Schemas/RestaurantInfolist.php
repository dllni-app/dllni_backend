<?php

declare(strict_types=1);

namespace App\Filament\Resources\Restaurants\Schemas;

use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Modules\Resturants\Enums\OrderStatus;

final class RestaurantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make(__('restaurant_admin.infolist.governance'))
                    ->schema([
                        TextEntry::make('is_active')
                            ->label(__('restaurant_admin.infolist.is_active'))
                            ->formatStateUsing(fn (?bool $state) => $state ? __('restaurant_admin.enums.boolean.yes') : __('restaurant_admin.enums.boolean.no'))
                            ->badge(),
                        TextEntry::make('suspension_until')->label(__('restaurant_admin.infolist.suspension_until'))->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('warning_count')->label(__('restaurant_admin.infolist.warning_count')),
                        TextEntry::make('reputation_score')->label(__('restaurant_admin.infolist.reputation_score'))->suffix(' / 100'),
                        TextEntry::make('visibility_score')->label(__('restaurant_admin.infolist.visibility_score')),
                    ])
                    ->columns(3),
                Section::make('بطاقة نقاط الثقة')
                    ->schema([
                        TextEntry::make('reputation_score')->label('النقاط')->suffix(' / 100')->weight('bold'),
                    ]),
                Section::make('بطاقة إحصائيات الأداء')
                    ->schema([
                        TextEntry::make('kpi_completed_orders')
                            ->label('إجمالي المهام المكتملة')
                            ->getStateUsing(fn ($record) => $record->orders()->where('status', OrderStatus::Completed->value)->count()),
                        TextEntry::make('kpi_acceptance_rate')
                            ->label('نسبة قبول الطلبات')
                            ->suffix('%')
                            ->getStateUsing(function ($record): float {
                                $total = max($record->orders()->count(), 1);
                                $accepted = $record->orders()->whereIn('status', [OrderStatus::Accepted->value, OrderStatus::Preparing->value, OrderStatus::Completed->value])->count();

                                return round(($accepted / $total) * 100, 2);
                            }),
                        TextEntry::make('kpi_cancellation_rate')
                            ->label('نسبة الإلغاء')
                            ->suffix('%')
                            ->getStateUsing(function ($record): float {
                                $total = max($record->orders()->count(), 1);
                                $cancelled = $record->orders()->where('status', OrderStatus::Cancelled->value)->count();

                                return round(($cancelled / $total) * 100, 2);
                            }),
                        TextEntry::make('average_rating')->label('متوسط التقييم العام'),
                        TextEntry::make('kpi_open_disputes')
                            ->label('عدد النزاعات المفتوحة')
                            ->getStateUsing(fn ($record) => $record->orders()->whereHas('disputes', fn ($q) => $q->whereIn('status', ['open', 'under_review']))->count()),
                    ])
                    ->columns(5),
            ]);
    }
}
