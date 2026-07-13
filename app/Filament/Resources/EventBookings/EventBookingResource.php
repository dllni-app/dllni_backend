<?php

declare(strict_types=1);

namespace App\Filament\Resources\EventBookings;

use App\Filament\Resources\EventBookings\Pages\ListEventBookings;
use App\Filament\Resources\EventBookings\Pages\ViewEventBooking;
use App\Filament\Resources\EventBookings\Schemas\EventBookingForm;
use App\Filament\Resources\EventBookings\Schemas\EventBookingInfolist;
use App\Filament\Resources\EventBookings\Tables\EventBookingsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\EventBooking;

final class EventBookingResource extends Resource
{
    protected static ?string $model = EventBooking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?int $navigationSort = 3;

    protected static bool $shouldRegisterNavigation = false;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_admin.event_bookings.nav_label');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('cleaning_admin.event_bookings.tooltip');
    }

    public static function form(Schema $schema): Schema
    {
        return EventBookingForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return EventBookingInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return EventBookingsTable::configure($table);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()
            ->with(['customer', 'cancellationPolicy', 'billingPolicy', 'services']);
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
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEventBookings::route('/'),
            'view' => ViewEventBooking::route('/{record}'),
        ];
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
