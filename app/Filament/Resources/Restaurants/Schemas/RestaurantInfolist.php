<?php

declare(strict_types=1);

namespace App\Filament\Resources\Restaurants\Schemas;

use App\Models\RestaurantFinancialSetting;
use Filament\Infolists\Components\Section;
use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Schema;
use Modules\Resturants\Enums\OrderStatus;
use Modules\Resturants\Enums\RestaurantDisputeStatus;
use Modules\Resturants\Models\Restaurant;

final class RestaurantInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('بيانات المطعم والتواصل')
                    ->schema([
                        TextEntry::make('name')->label('اسم المطعم'),
                        TextEntry::make('user.name')->label('المالك')->placeholder('—'),
                        TextEntry::make('phone')->label('هاتف المطعم')->placeholder('—'),
                        TextEntry::make('email')->label('البريد')->placeholder('—'),
                        TextEntry::make('address')->label('العنوان')->placeholder('—'),
                        TextEntry::make('city')->label('المدينة')->placeholder('—'),
                        TextEntry::make('district')->label('الحي')->placeholder('—'),
                        TextEntry::make('coordinates')
                            ->label('الإحداثيات')
                            ->state(fn (Restaurant $record): string => ($record->latitude !== null && $record->longitude !== null)
                                ? $record->latitude.', '.$record->longitude
                                : '—'),
                    ])
                    ->columns(4),

                Section::make(__('restaurant_admin.infolist.governance'))
                    ->schema([
                        TextEntry::make('is_active')->label(__('restaurant_admin.infolist.is_active'))
                            ->formatStateUsing(fn (?bool $state): string => $state ? 'نعم' : 'لا')->badge(),
                        TextEntry::make('is_temporarily_closed')->label('مغلق مؤقتاً')
                            ->formatStateUsing(fn (?bool $state): string => $state ? 'نعم' : 'لا')->badge(),
                        TextEntry::make('suspension_until')->label(__('restaurant_admin.infolist.suspension_until'))->dateTime('Y-m-d H:i')->placeholder('—'),
                        TextEntry::make('warning_count')->label(__('restaurant_admin.infolist.warning_count')),
                        TextEntry::make('reputation_score')->label(__('restaurant_admin.infolist.reputation_score'))->suffix(' / 100'),
                        TextEntry::make('visibility_score')->label(__('restaurant_admin.infolist.visibility_score')),
                    ])
                    ->columns(3),

                Section::make('المتابعة التشغيلية')
                    ->description('قراءة مباشرة من بيانات المطعم والطلبات، بدون إنشاء دورة تشغيل موازية في لوحة الإدارة.')
                    ->schema([
                        TextEntry::make('operating_hours_summary')
                            ->label('ساعات العمل')
                            ->state(function (Restaurant $record): string {
                                $rows = $record->operatingHours()->orderBy('day_of_week')->orderBy('open_time')->get();
                                if ($rows->isEmpty()) {
                                    return 'لم يتم تعريف ساعات العمل';
                                }

                                return $rows->map(function ($row): string {
                                    $day = $row->day_of_week?->value ?? (string) $row->day_of_week;

                                    return $row->is_closed
                                        ? $day.': مغلق'
                                        : $day.': '.($row->open_time ?? '—').' - '.($row->close_time ?? '—');
                                })->implode(' | ');
                            })
                            ->columnSpanFull(),
                        TextEntry::make('open_orders_count')->label('طلبات مفتوحة')->badge()
                            ->state(fn (Restaurant $record): int => $record->orders()->whereIn('status', [
                                OrderStatus::Pending->value,
                                OrderStatus::Accepted->value,
                                OrderStatus::Preparing->value,
                                OrderStatus::ReadyForPickup->value,
                                OrderStatus::PickedUp->value,
                            ])->count()),
                        TextEntry::make('low_stock_count')->label('منتجات منخفضة المخزون')->badge()
                            ->state(fn (Restaurant $record): int => $record->products()->whereColumn('stock_quantity', '<=', 'low_stock_threshold')->count()),
                        TextEntry::make('open_disputes_count')->label('نزاعات مفتوحة')->badge()
                            ->state(fn (Restaurant $record): int => $record->orders()->whereHas('disputes', fn ($q) => $q->whereIn('status', [
                                RestaurantDisputeStatus::Open->value,
                                RestaurantDisputeStatus::UnderReview->value,
                            ]))->count()),
                        TextEntry::make('pending_documents_count')->label('وثائق بانتظار المراجعة')->badge()
                            ->state(fn (Restaurant $record): int => $record->documents()->where('verification_status', 'pending')->count()),
                        TextEntry::make('expiring_documents_count')->label('وثائق تنتهي خلال 30 يوم')->badge()
                            ->state(fn (Restaurant $record): int => $record->documents()->whereNotNull('expires_at')->whereBetween('expires_at', [now(), now()->addDays(30)])->count()),
                        TextEntry::make('expired_documents_count')->label('وثائق منتهية')->badge()
                            ->state(fn (Restaurant $record): int => $record->documents()->whereNotNull('expires_at')->where('expires_at', '<', now())->count()),
                    ])
                    ->columns(3),

                Section::make('الملخص المالي')
                    ->schema([
                        TextEntry::make('commission_setting')
                            ->label('قاعدة عمولة المنصة')
                            ->state(function (): string {
                                $setting = RestaurantFinancialSetting::query()->latest('id')->first();
                                if ($setting === null) {
                                    return 'لا يوجد إعداد عمولة - الطلبات الجديدة تستخدم عمولة صفر';
                                }

                                $value = number_format((float) $setting->commission_value, 2);

                                return $setting->commission_type === 'percent'
                                    ? $value.'%'
                                    : $value.' ل.س';
                            })
                            ->badge(),
                        TextEntry::make('merchant_net_30d')
                            ->label('صافي مستحق المطعم - 30 يوم')
                            ->state(fn (Restaurant $record): string => number_format((float) $record->orders()
                                ->where('status', OrderStatus::Completed->value)
                                ->where('created_at', '>=', now()->subDays(29)->startOfDay())
                                ->sum('merchant_net_amount'), 2).' ل.س'),
                        TextEntry::make('platform_commission_30d')
                            ->label('عمولة المنصة - 30 يوم')
                            ->state(fn (Restaurant $record): string => number_format((float) $record->orders()
                                ->where('status', OrderStatus::Completed->value)
                                ->where('created_at', '>=', now()->subDays(29)->startOfDay())
                                ->sum('commission_amount'), 2).' ل.س'),
                        TextEntry::make('unsnapshotted_orders_count')
                            ->label('طلبات مكتملة بدون snapshot')->badge()
                            ->state(fn (Restaurant $record): int => $record->orders()
                                ->where('status', OrderStatus::Completed->value)
                                ->whereNull('financial_snapshot')
                                ->count()),
                    ])
                    ->columns(4),

                Section::make('مؤشرات الأداء')
                    ->schema([
                        TextEntry::make('kpi_completed_orders')->label('إجمالي المهام المكتملة')
                            ->state(fn (Restaurant $record): int => $record->orders()->where('status', OrderStatus::Completed->value)->count()),
                        TextEntry::make('kpi_acceptance_rate')->label('نسبة قبول الطلبات')->suffix('%')
                            ->state(function (Restaurant $record): float {
                                $total = max($record->orders()->count(), 1);
                                $accepted = $record->orders()->whereIn('status', [
                                    OrderStatus::Accepted->value,
                                    OrderStatus::Preparing->value,
                                    OrderStatus::ReadyForPickup->value,
                                    OrderStatus::PickedUp->value,
                                    OrderStatus::Completed->value,
                                ])->count();

                                return round(($accepted / $total) * 100, 2);
                            }),
                        TextEntry::make('kpi_cancellation_rate')->label('نسبة الإلغاء')->suffix('%')
                            ->state(function (Restaurant $record): float {
                                $total = max($record->orders()->count(), 1);
                                $cancelled = $record->orders()->where('status', OrderStatus::Cancelled->value)->count();

                                return round(($cancelled / $total) * 100, 2);
                            }),
                        TextEntry::make('average_rating')->label('متوسط التقييم العام'),
                    ])
                    ->columns(4),
            ]);
    }
}
