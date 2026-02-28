<?php

namespace App\Filament\CleaningAdmin\Resources\CleaningBookings;

use App\Filament\CleaningAdmin\Resources\CleaningBookings\Pages\CreateCleaningBooking;
use App\Filament\CleaningAdmin\Resources\CleaningBookings\Pages\EditCleaningBooking;
use App\Filament\CleaningAdmin\Resources\CleaningBookings\Pages\ListCleaningBookings;
use App\Filament\CleaningAdmin\Resources\CleaningBookings\Pages\ViewCleaningBooking;
use App\Filament\CleaningAdmin\Resources\CleaningBookings\Schemas\CleaningBookingForm;
use App\Filament\CleaningAdmin\Resources\CleaningBookings\Schemas\CleaningBookingInfolist;
use App\Filament\CleaningAdmin\Resources\CleaningBookings\Tables\CleaningBookingsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\Cleaning\Models\CleaningBooking;

class CleaningBookingResource extends Resource
{
    protected static ?string $model = CleaningBooking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'Cleaning Bookings';

    protected static ?string $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 2;

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

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningBookings::route('/'),
            'create' => CreateCleaningBooking::route('/create'),
            'view' => ViewCleaningBooking::route('/{record}'),
            'edit' => EditCleaningBooking::route('/{record}/edit'),
        ];
    }
}
