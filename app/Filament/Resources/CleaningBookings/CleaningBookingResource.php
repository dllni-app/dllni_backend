<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings;

use App\Enums\DisputeStatus;
use App\Enums\SupportCaseKind;
use App\Enums\SupportCaseStatus;
use App\Filament\Resources\CleaningBookings\Pages\EditCleaningBooking;
use App\Filament\Resources\CleaningBookings\Pages\ListCleaningBookings;
use App\Filament\Resources\CleaningBookings\Pages\ViewCleaningBooking;
use App\Filament\Resources\CleaningBookings\Schemas\CleaningBookingForm;
use App\Filament\Resources\CleaningBookings\Schemas\CleaningBookingInfolist;
use App\Filament\Resources\CleaningBookings\Tables\CleaningBookingsTable;
use App\Models\SupportCase;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Enums\CleaningBookingStatus;
use Modules\Cleaning\Models\CleaningBooking;

final class CleaningBookingResource extends Resource
{
    protected static ?string $model = CleaningBooking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 2;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.cleaning_bookings.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.cleaning_bookings.tooltip');
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_admin.cleaning_bookings.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_admin.cleaning_bookings.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return CleaningBookingForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CleaningBookingInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CleaningBookingsTable::configure($table)
            ->pushColumns([
                TextColumn::make('open_dispute_status')
                    ->label('نزاع مفتوح')
                    ->getStateUsing(function (CleaningBooking $record): string {
                        $count = (int) ($record->open_disputes_count ?? 0)
                            + (int) ($record->open_support_cases_count ?? 0);

                        return $count > 0 ? 'open' : 'none';
                    })
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => $state === 'open' ? 'يوجد نزاع مفتوح' : 'لا يوجد نزاع مفتوح')
                    ->color(fn (string $state): string => $state === 'open' ? 'danger' : 'gray')
                    ->icon(fn (string $state): string => $state === 'open' ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle'),
                TextColumn::make('cancelledByWorker.first_name')
                    ->label('العامل الذي ألغى')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('cancellation_offset_minutes')
                    ->label('توقيت الإلغاء')
                    ->formatStateUsing(fn ($state): string => self::cancellationTimingLabel($state))
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('financialPenalty.amount')
                    ->label('الغرامة المالية')
                    ->formatStateUsing(fn ($state): string => $state === null ? '-' : number_format((float) $state, 0).' '.config('app.currency', 'SYP'))
                    ->badge()
                    ->color('danger')
                    ->toggleable(isToggledHiddenByDefault: true),
            ]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with([
                'customer',
                'worker.user',
                'preferredWorker.user',
                'cancelledByUser',
                'cancelledByWorker.user',
                'financialPenalty',
                'rooms.assignedWorker.user',
                'rooms.plannedPreferredWorker.user',
                'workerAssignments.worker.user',
                'acceptedWorkerAssignments.worker.user',
            ])
            ->withCount([
                'disputes as open_disputes_count' => fn (Builder $query): Builder => $query->whereIn('status', [
                    DisputeStatus::Open->value,
                    DisputeStatus::UnderReview->value,
                ]),
            ])
            ->addSelect([
                'open_support_cases_count' => SupportCase::query()
                    ->selectRaw('COUNT(*)')
                    ->whereColumn('support_cases.booking_id', 'cleaning_bookings.id')
                    ->where('support_cases.booking_type', CleaningBooking::class)
                    ->where('support_cases.kind', SupportCaseKind::Complaint->value)
                    ->whereIn('support_cases.status', SupportCaseStatus::activeValues()),
            ]);
    }

    public static function canViewAny(): bool
    {
        return self::hasPermission('bookings.view');
    }

    public static function canView(Model $record): bool
    {
        return self::hasPermission('bookings.view');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        if (! self::hasPermission('bookings.update')) {
            return false;
        }

        if (! $record instanceof CleaningBooking) {
            return false;
        }

        $status = $record->status instanceof CleaningBookingStatus
            ? $record->status
            : CleaningBookingStatus::tryFrom((string) $record->status);

        return ! in_array($status, [
            CleaningBookingStatus::Completed,
            CleaningBookingStatus::Cancelled,
        ], true);
    }

    public static function canApplyFinancialPenalty(CleaningBooking $record): bool
    {
        return self::hasPermission('bookings.update');
    }

    public static function canDelete(Model $record): bool
    {
        return self::hasPermission('bookings.delete');
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningBookings::route('/'),
            'view' => ViewCleaningBooking::route('/{record}'),
            'edit' => EditCleaningBooking::route('/{record}/edit'),
        ];
    }

    private static function cancellationTimingLabel(mixed $value): string
    {
        if (! is_numeric($value)) {
            return '-';
        }

        $minutes = (int) $value;

        return $minutes > 0
            ? "قبل الموعد بـ {$minutes} دقيقة"
            : ($minutes < 0 ? 'بعد الموعد بـ '.abs($minutes).' دقيقة' : 'في موعد العمل');
    }

    private static function hasPermission(string $permission): bool
    {
        $user = auth()->user();

        if (! $user) {
            return false;
        }

        if ($user->hasAnyRole(['admin', 'Super Admin'])) {
            return true;
        }

        return $user->can($permission);
    }
}
