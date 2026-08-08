<?php

declare(strict_types=1);

namespace App\Filament\Resources\SupportCases\Tables;

use App\Enums\DisputeCategory;
use App\Enums\EmergencyType;
use App\Enums\SupportCaseKind;
use App\Enums\SupportCasePriority;
use App\Enums\SupportCaseStatus;
use App\Models\SupportCase;
use App\Support\SupportCaseBookingPresentation;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

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
                TextColumn::make('service_type')
                    ->label('القسم')
                    ->badge()
                    ->getStateUsing(fn (SupportCase $record): string => SupportCaseBookingPresentation::typeLabel($record))
                    ->color('info'),
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
                TextColumn::make('booking_reference')
                    ->label('رقم الطلب / الحجز')
                    ->getStateUsing(fn (SupportCase $record): string => SupportCaseBookingPresentation::reference($record))
                    ->searchable(query: fn ($query, string $search) => SupportCaseBookingPresentation::applyReferenceSearch($query, $search)),
                TextColumn::make('reporter.name')
                    ->label('المبلّغ')
                    ->placeholder('-')
                    ->searchable(),
                TextColumn::make('reporter.phone')
                    ->label('هاتف المبلّغ')
                    ->placeholder('-')
                    ->copyable(),
                TextColumn::make('other_party_phone')
                    ->label('هاتف الطرف المرتبط')
                    ->getStateUsing(fn (SupportCase $record): ?string => SupportCaseBookingPresentation::counterpartPhone($record))
                    ->placeholder('-')
                    ->copyable(),
                TextColumn::make('category')
                    ->label('التصنيف')
                    ->badge()
                    ->formatStateUsing(fn ($state, SupportCase $record): string => self::categoryLabel($record, (string) $state))
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
                Filter::make('service_type')
                    ->label('القسم')
                    ->form([
                        Select::make('value')
                            ->label('القسم')
                            ->options(SupportCaseBookingPresentation::typeOptions()),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $type = $data['value'] ?? null;

                        return $query->when(
                            filled($type),
                            fn (Builder $query): Builder => $query->where(
                                'booking_type',
                                SupportCaseBookingPresentation::storedType((string) $type),
                            ),
                        );
                    }),
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

    private static function categoryLabel(SupportCase $record, string $category): string
    {
        if ($record->kind === SupportCaseKind::Emergency) {
            return match (EmergencyType::tryFrom($category)) {
                EmergencyType::SafetyThreat => 'تهديد أو عدم أمان',
                EmergencyType::MedicalEmergency => 'حالة طبية طارئة',
                EmergencyType::SevereConflict => 'خلاف حاد',
                default => $category,
            };
        }

        return DisputeCategory::tryFrom($category)?->label() ?? $category;
    }
}
