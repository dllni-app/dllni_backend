<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningV3VisitDispatches;

use App\Filament\Clusters\CleaningOperationsCluster;
use App\Filament\Resources\CleaningV3VisitDispatches\Pages\ListCleaningV3VisitDispatches;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Enums\CleaningBookingWorkerAssignmentStatus;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;
use Modules\Cleaning\Models\CleaningV3VisitDispatch;

final class CleaningV3VisitDispatchResource extends Resource
{
    protected static ?string $model = CleaningV3VisitDispatch::class;

    protected static ?string $cluster = CleaningOperationsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = 'heroicon-o-user-group';

    protected static ?int $navigationSort = 9;

    public static function getNavigationLabel(): string
    {
        return 'الحجوزات ناقصة الفريق';
    }

    public static function getModelLabel(): string
    {
        return 'زيارة قيد البحث';
    }

    public static function getPluralModelLabel(): string
    {
        return 'زيارات التنظيف الناقصة';
    }

    public static function canViewAny(): bool
    {
        return auth()->user()?->hasAnyRole(['admin', 'Super Admin']) === true
            || auth()->user()?->can('cleaning-v3-dispatch.view') === true;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereNull('resolved_at')
            ->whereHas('session.booking', static fn (Builder $query): Builder => $query->where('contract_version', 'v3')->operationallyAdmitted())
            ->whereHas('session', static fn (Builder $query): Builder => $query->whereIn('status', ['scheduled', 'worker_assigned'])
                ->whereRaw('(SELECT COUNT(DISTINCT a.worker_id) FROM cleaning_booking_session_worker_assignments a WHERE a.cleaning_booking_session_id = cleaning_booking_sessions.id AND a.released_at IS NULL AND a.status IN ('.implode(',', array_fill(0, count(CleaningBookingWorkerAssignmentStatus::acceptedValues()), '?')).')) < cleaning_booking_sessions.required_workers', CleaningBookingWorkerAssignmentStatus::acceptedValues()))
            ->with(['session.booking', 'session.workerAssignments']);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('session.booking.booking_number')
                    ->label('رقم الطلب')
                    ->url(fn (CleaningV3VisitDispatch $record): string => \App\Filament\Resources\CleaningBookings\CleaningBookingResource::getUrl('view', ['record' => $record->session->cleaning_booking_id]))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('session.sequence')
                    ->label('تسلسل التنفيذ')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('session.scheduled_date')
                    ->label('التاريخ')
                    ->date('Y-m-d'),
                TextColumn::make('session.scheduled_time')
                    ->label('الوقت'),
                TextColumn::make('session.required_workers')
                    ->label('العمال المطلوبون'),
                TextColumn::make('accepted_workers')
                    ->label('العمال المقبولون')
                    ->state(static fn (CleaningV3VisitDispatch $record): int => self::acceptedCount($record)),
                TextColumn::make('deadline_at')
                    ->label('مهلة البحث')
                    ->dateTime('Y-m-d H:i')
                    ->sortable(),
                TextColumn::make('escalated_at')
                    ->label('التصعيد')
                    ->dateTime('Y-m-d H:i')
                    ->placeholder('لم يتم')
                    ->sortable(),
            ])
            ->defaultSort('deadline_at', 'asc');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningV3VisitDispatches::route('/'),
        ];
    }

    private static function acceptedCount(CleaningV3VisitDispatch $record): int
    {
        $session = $record->session;
        if ($session === null) {
            return 0;
        }

        return $session->workerAssignments->filter(
            static fn (CleaningBookingSessionWorkerAssignment $assignment): bool => $assignment->released_at === null
                && in_array(
                    (string) ($assignment->status?->value ?? $assignment->status),
                    CleaningBookingWorkerAssignmentStatus::acceptedValues(),
                    true,
                ),
        )->count();
    }
}
