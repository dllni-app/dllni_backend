<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmStores\Schemas;

use Filament\Infolists\Components\TextEntry;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Modules\Supermarket\Enums\SmCommissionType;
use Modules\Supermarket\Enums\SmDisputeStatus;
use Modules\Supermarket\Enums\SmOrderStatus;
use Modules\Supermarket\Models\SmOrderDispute;
use Modules\Supermarket\Models\SmStore;

final class SmStoreInfolist
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->components([
                Section::make('بيانات المتجر والتواصل')
                    ->schema([
                        TextEntry::make('name')->label('اسم المتجر'),
                        TextEntry::make('owner.name')->label('مالك المتجر')->placeholder('—'),
                        TextEntry::make('owner.phone')->label('هاتف المالك')->placeholder('—'),
                        TextEntry::make('owner.email')->label('بريد المالك')->placeholder('—'),
                        TextEntry::make('phone')->label('هاتف المتجر')->placeholder('—'),
                        TextEntry::make('email')->label('بريد المتجر')->placeholder('—'),
                        TextEntry::make('address')->label('العنوان')->placeholder('—'),
                        TextEntry::make('city')->label('المدينة')->placeholder('—'),
                        TextEntry::make('neighborhood')->label('الحي')->placeholder('—'),
                        TextEntry::make('coordinates')
                            ->label('الإحداثيات')
                            ->state(fn (SmStore $record): string => ($record->latitude !== null && $record->longitude !== null)
                                ? $record->latitude.', '.$record->longitude
                                : '—'),
                    ])
                    ->columns(3),

                Section::make('الحوكمة والحالة')
                    ->schema([
                        TextEntry::make('trust_score')->label('نقاط الثقة')->suffix(' / 100'),
                        TextEntry::make('warning_count')->label('عدد التحذيرات'),
                        TextEntry::make('average_rating')->label('متوسط التقييم')->placeholder('—'),
                        TextEntry::make('total_reviews')->label('عدد التقييمات'),
                        TextEntry::make('is_active')->label('فعال')->formatStateUsing(fn (?bool $s): string => $s ? 'نعم' : 'لا')->badge(),
                        TextEntry::make('is_featured')->label('متجر مميز')->formatStateUsing(fn (?bool $s): string => $s ? 'نعم' : 'لا')->badge(),
                        TextEntry::make('suspension_until')->label('التعليق حتى')->dateTime('Y-m-d H:i')->placeholder('غير معلق'),
                    ])
                    ->columns(3),

                Section::make('المتابعة التشغيلية')
                    ->description('قراءة مباشرة من نفس بيانات تطبيق المالك والطلبات، بدون إنشاء حالة تشغيلية موازية في لوحة الإدارة.')
                    ->schema([
                        TextEntry::make('operating_hours_summary')
                            ->label('ساعات العمل')
                            ->state(function (SmStore $record): string {
                                $rows = $record->storeHours()->orderBy('id')->get();

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
                        TextEntry::make('open_orders_count')
                            ->label('طلبات مفتوحة')
                            ->state(fn (SmStore $record): int => $record->orders()
                                ->whereIn('status', [
                                    SmOrderStatus::Pending->value,
                                    SmOrderStatus::Accepted->value,
                                    SmOrderStatus::Preparing->value,
                                    SmOrderStatus::ReadyForPickup->value,
                                    SmOrderStatus::PickedUp->value,
                                ])->count())
                            ->badge(),
                        TextEntry::make('low_stock_count')
                            ->label('منتجات منخفضة المخزون')
                            ->state(fn (SmStore $record): int => $record->products()
                                ->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
                                ->count())
                            ->badge(),
                        TextEntry::make('open_disputes_count')
                            ->label('نزاعات مفتوحة')
                            ->state(fn (SmStore $record): int => SmOrderDispute::query()
                                ->whereHas('order', fn ($query) => $query->where('store_id', $record->id))
                                ->whereIn('status', [SmDisputeStatus::Open->value, SmDisputeStatus::UnderReview->value])
                                ->count())
                            ->badge(),
                        TextEntry::make('pending_documents_count')
                            ->label('وثائق بانتظار المراجعة')
                            ->state(fn (SmStore $record): int => $record->documents()->where('verification_status', 'pending')->count())
                            ->badge(),
                        TextEntry::make('expiring_documents_count')
                            ->label('وثائق تنتهي خلال 30 يوم')
                            ->state(fn (SmStore $record): int => $record->documents()
                                ->whereNotNull('expires_at')
                                ->whereBetween('expires_at', [now(), now()->addDays(30)])
                                ->count())
                            ->badge(),
                        TextEntry::make('expired_documents_count')
                            ->label('وثائق منتهية')
                            ->state(fn (SmStore $record): int => $record->documents()
                                ->whereNotNull('expires_at')
                                ->where('expires_at', '<', now())
                                ->count())
                            ->badge(),
                        TextEntry::make('active_commission_rule')
                            ->label('قاعدة العمولة الفعالة')
                            ->state(function (SmStore $record): string {
                                $now = now();
                                $rule = $record->commissionRules()
                                    ->where('is_active', true)
                                    ->where(fn ($query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                                    ->where(fn ($query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now))
                                    ->orderByDesc('is_default')
                                    ->orderByDesc('starts_at')
                                    ->orderByDesc('id')
                                    ->first();

                                if ($rule === null) {
                                    return 'لا توجد قاعدة عمولة فعالة';
                                }

                                $type = $rule->commission_type === SmCommissionType::Percentage ? 'نسبة' : 'مبلغ ثابت';
                                $value = $rule->commission_type === SmCommissionType::Percentage
                                    ? number_format((float) $rule->value, 2).'%'
                                    : number_format((float) $rule->value, 2).' ل.س';

                                return $type.' - '.$value;
                            })
                            ->badge(),
                    ])
                    ->columns(3),
            ]);
    }
}
