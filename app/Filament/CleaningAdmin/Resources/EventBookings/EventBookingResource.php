<?php

namespace App\Filament\CleaningAdmin\Resources\EventBookings;

use App\Filament\CleaningAdmin\Resources\EventBookings\Pages\CreateEventBooking;
use App\Filament\CleaningAdmin\Resources\EventBookings\Pages\EditEventBooking;
use App\Filament\CleaningAdmin\Resources\EventBookings\Pages\ListEventBookings;
use App\Filament\CleaningAdmin\Resources\EventBookings\Pages\ViewEventBooking;
use App\Filament\CleaningAdmin\Resources\EventBookings\Schemas\EventBookingForm;
use App\Filament\CleaningAdmin\Resources\EventBookings\Schemas\EventBookingInfolist;
use App\Filament\CleaningAdmin\Resources\EventBookings\Tables\EventBookingsTable;
use Modules\Cleaning\Models\EventBooking;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;

class EventBookingResource extends Resource
{
    protected static ?string $model = EventBooking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?string $navigationLabel = 'Event Bookings';

    protected static ?string $navigationGroup = 'Bookings';

    protected static ?int $navigationSort = 3;

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

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListEventBookings::route('/'),
            'create' => CreateEventBooking::route('/create'),
            'view' => ViewEventBooking::route('/{record}'),
            'edit' => EditEventBooking::route('/{record}/edit'),
        ];
    }
}
