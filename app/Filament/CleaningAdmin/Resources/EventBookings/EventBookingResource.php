<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\EventBookings;

use App\Filament\CleaningAdmin\Resources\EventBookings\Pages\ListEventBookings;
use App\Filament\CleaningAdmin\Resources\EventBookings\Pages\ViewEventBooking;
use App\Filament\CleaningAdmin\Resources\EventBookings\Schemas\EventBookingForm;
use App\Filament\CleaningAdmin\Resources\EventBookings\Schemas\EventBookingInfolist;
use App\Filament\CleaningAdmin\Resources\EventBookings\Tables\EventBookingsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\Cleaning\Models\EventBooking;
use UnitEnum;

final class EventBookingResource extends Resource
{
    protected static ?string $model = EventBooking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendar;

    protected static ?string $navigationLabel = 'حجوزات المناسبات';

    protected static string|UnitEnum|null $navigationGroup = 'قسم التنظيف';

    protected static ?int $navigationSort = 3;

    public static function getNavigationTooltip(): ?string
    {
        return 'عرض وإدارة حجوزات المناسبات: عشاء عائلي، عيد ميلاد، تجمع كبير، جنازة؛ نطاق الضيوف، حجم الفريق، الحالة والسعر.';
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
}
