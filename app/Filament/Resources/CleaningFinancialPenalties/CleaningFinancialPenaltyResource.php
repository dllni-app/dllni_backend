<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningFinancialPenalties;

use App\Filament\Resources\CleaningFinancialPenalties\Pages\ListCleaningFinancialPenalties;
use App\Filament\Resources\CleaningFinancialPenalties\Pages\ViewCleaningFinancialPenalty;
use App\Models\CleaningFinancialPenalty;
use BackedEnum;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

final class CleaningFinancialPenaltyResource extends Resource
{
    protected static ?string $model = CleaningFinancialPenalty::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBanknotes;

    protected static ?int $navigationSort = 38;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return 'الغرامات المالية';
    }

    public static function getModelLabel(): string
    {
        return 'غرامة مالية';
    }

    public static function getPluralModelLabel(): string
    {
        return 'الغرامات المالية';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('تفاصيل الغرامة')
                ->schema([
                    TextEntry::make('booking.booking_number')->label('رقم الطلب'),
                    TextEntry::make('penalized_role')
                        ->label('الطرف الملغِي')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => $state === CleaningFinancialPenalty::ROLE_CUSTOMER ? 'المستخدم' : 'العامل'),
                    TextEntry::make('worker.user.name')->label('العامل')->placeholder('-'),
                    TextEntry::make('customer.name')->label('المستخدم')->placeholder('-'),
                    TextEntry::make('amount')->label('قيمة الغرامة')->money('SYP'),
                    TextEntry::make('review_status')
                        ->label('المراجعة')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => $state === CleaningFinancialPenalty::REVIEW_REVIEWED ? 'تمت مراجعتها' : 'تحتاج مراجعة'),
                    TextEntry::make('status')
                        ->label('حالة الغرامة')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            CleaningFinancialPenalty::STATUS_CANCELLED => 'ملغاة',
                            CleaningFinancialPenalty::STATUS_CLEARED => 'مصفرّة',
                            default => 'فعالة',
                        }),
                    TextEntry::make('financial_source')
                        ->label('المصدر المالي')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => match ($state) {
                            CleaningFinancialPenalty::SOURCE_DEPOSIT => 'رصيد الإيداع',
                            CleaningFinancialPenalty::SOURCE_DEBT => 'الدين',
                            CleaningFinancialPenalty::SOURCE_MIXED => 'إيداع + دين',
                            CleaningFinancialPenalty::SOURCE_CUSTOMER_FEE => 'غرامة مستخدم',
                            default => $state,
                        }),
                    TextEntry::make('notes')->label('الملاحظات')->columnSpanFull(),
                    TextEntry::make('cancellation_reason_snapshot')->label('سبب إلغاء الطلب')->placeholder('-')->columnSpanFull(),
                    TextEntry::make('cancellation_offset_minutes')
                        ->label('التوقيت بالنسبة للموعد')
                        ->formatStateUsing(function ($state): string {
                            if ($state === null) {
                                return '-';
                            }
                            $minutes = abs((int) $state);
                            return (int) $state > 0 ? "قبل الموعد بـ {$minutes} دقيقة" : ((int) $state < 0 ? "بعد الموعد بـ {$minutes} دقيقة" : 'عند موعد البدء');
                        }),
                    TextEntry::make('reviewedByAdmin.name')->label('تمت مراجعتها بواسطة')->placeholder('-'),
                    TextEntry::make('reviewed_at')->label('تاريخ المراجعة')->dateTime()->placeholder('-'),
                    TextEntry::make('appliedByAdmin.name')->label('أضيفت بواسطة')->placeholder('تلقائي'),
                    TextEntry::make('applied_at')->label('تاريخ التسجيل')->dateTime(),
                    TextEntry::make('cancelledByAdmin.name')->label('ألغيت بواسطة')->placeholder('-'),
                    TextEntry::make('penalty_cancelled_at')->label('تاريخ إلغاء الغرامة')->dateTime()->placeholder('-'),
                    TextEntry::make('penalty_cancellation_note')->label('سبب إلغاء الغرامة')->placeholder('-')->columnSpanFull(),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return Tables\CleaningFinancialPenaltiesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningFinancialPenalties::route('/'),
            'view' => ViewCleaningFinancialPenalty::route('/{record}'),
        ];
    }
}
