<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningAttendanceIncidents;

use App\Filament\Resources\CleaningAttendanceIncidents\Pages\ListCleaningAttendanceIncidents;
use App\Filament\Resources\CleaningAttendanceIncidents\Pages\ViewCleaningAttendanceIncident;
use App\Filament\Resources\CleaningAttendanceIncidents\Schemas\CleaningAttendanceIncidentInfolist;
use App\Filament\Resources\CleaningAttendanceIncidents\Tables\CleaningAttendanceIncidentsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Modules\Cleaning\Models\CleaningBookingSession;
use Modules\Cleaning\Models\CleaningBookingSessionWorkerAssignment;

final class CleaningAttendanceIncidentResource extends Resource
{
    protected static ?string $model = CleaningBookingSessionWorkerAssignment::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?int $navigationSort = 34;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return 'بلاغات تأخر العمال';
    }

    public static function getModelLabel(): string
    {
        return 'بلاغ حضور';
    }

    public static function getPluralModelLabel(): string
    {
        return 'بلاغات الحضور';
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function infolist(Schema $schema): Schema
    {
        return CleaningAttendanceIncidentInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CleaningAttendanceIncidentsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->whereHas('session', fn (Builder $query): Builder => $query
                ->where('session_type', CleaningBookingSession::TYPE_RECURRING_CLEANING))
            ->where(function (Builder $query): void {
                $query
                    ->whereNotNull('late_reported_at')
                    ->orWhereNotNull('no_travel_reported_at');
            })
            ->with([
                'worker.user',
                'session.booking.customer',
            ]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningAttendanceIncidents::route('/'),
            'view' => ViewCleaningAttendanceIncident::route('/{record}'),
        ];
    }
}
