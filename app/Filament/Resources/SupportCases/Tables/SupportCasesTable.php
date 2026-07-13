<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportCases\Tables;

use App\Enums\SupportCaseKind;
use App\Enums\SupportCasePriority;
use App\Enums\SupportCaseStatus;
use App\Models\SupportCase;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Modules\Cleaning\Models\CleaningBooking;

final class SupportCasesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('case_number')
                    ->label('رقم البلاغ')
                    ->searchable()
                    ->sortable()
                    ->copyable(),
                TextColumn::make('kind')
                    ->label('النوع')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? (string) $state)
                    ->color(fn ($state): string => ($state?->value ?? $state) === SupportCaseKind::Emergency->value ? 'danger' : 'warning'),
                TextColumn::make('priority')
                    ->label('الأولوية')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? (string) $state)
                    ->color(fn ($state): string => match ($state?->value ?? $state) {
                        SupportCasePriority::Critical->value => 'danger',
                        SupportCasePriority::High->value => 'warning',
                        default => 'gray',
                    }),
                TextColumn::make('reporter_role')
                    ->label('مصدر البلاغ')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? (string) $state),
                TextColumn::make('booking_number')
                    ->label('رقم الحجز')
                    ->getStateUsing(fn (SupportCase $record): string => $record->booking?->booking_number ?? '-')
                    ->searchable(query: function ($query, string $search) {
                        return $query->whereHasMorph('booking', [CleaningBooking::class], fn ($bookingQuery) => $bookingQuery->where('booking_number', 'like', "%{$search}%"));
                    }),
                TextColumn::make('reporter.name')
                    ->label('المبلّغ')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('reporter.phone')
                    ->label('هاتف المبلّغ')
                    ->placeholder('-')
                    ->copyable(),
                TextColumn::make('other_party_phone')
                    ->label('هاتف الطرف الآخر')
                    ->getStateUsing(fn (SupportCase $record): ?string => self::otherPartyPhone($record))
                    ->placeholder('-')
                    ->copyable(),
                TextColumn::make('category')
                    ->label('التصنيف')
                    ->badge()
                    ->color('gray'),
                TextColumn::make('status')
                    ->label('الحالة')
                    ->badge()
                    ->formatStateUsing(fn ($state): string => $state?->label() ?? (string) $state)
                    ->color(fn ($state): string => match ($state?->value ?? $state) {
                        SupportCaseStatus::New->value => 'danger',
                        SupportCaseStatus::Acknowledged->value,
                        SupportCaseStatus::UnderReview->value,
                        SupportCaseStatus::WaitingParty->value => 'warning',
                        SupportCaseStatus::Resolved->value => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('created_at')
                    ->label('تاريخ الإنشاء')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('kind')
                    ->label('نوع البلاغ')
                    ->options(collect(SupportCaseKind::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()),
                SelectFilter::make('status')
                    ->label('الحالة')
                    ->options(collect(SupportCaseStatus::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()),
                SelectFilter::make('priority')
                    ->label('الأولوية')
                    ->options(collect(SupportCasePriority::cases())->mapWithKeys(fn ($case) => [$case->value => $case->label()])->all()),
            ])
            ->recordActions([
                ViewAction::make()->label('عرض'),
            ]);
    }

    private static function otherPartyPhone(SupportCase $record): ?string
    {
        $booking = $record->booking;
        if (! $booking instanceof CleaningBooking) {
            return null;
        }

        $reporterRole = $record->reporter_role?->value ?? $record->reporter_role;

        return $reporterRole === 'worker'
            ? $booking->customer?->phone
            : $booking->worker?->user?->phone;
    }
}
