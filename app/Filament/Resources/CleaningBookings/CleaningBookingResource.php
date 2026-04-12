<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningBookings;

use App\Filament\Resources\CleaningBookings\Pages\EditCleaningBooking;
use App\Filament\Resources\CleaningBookings\Pages\ListCleaningBookings;
use App\Filament\Resources\CleaningBookings\Pages\ViewCleaningBooking;
use App\Filament\Resources\CleaningBookings\Schemas\CleaningBookingForm;
use App\Filament\Resources\CleaningBookings\Schemas\CleaningBookingInfolist;
use App\Filament\Resources\CleaningBookings\Tables\CleaningBookingsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
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
        return CleaningBookingsTable::configure($table);
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
