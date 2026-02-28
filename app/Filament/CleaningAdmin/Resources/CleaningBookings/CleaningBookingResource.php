<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningBookings;

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
use UnitEnum;

final class CleaningBookingResource extends Resource
{
    protected static ?string $model = CleaningBooking::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?string $navigationLabel = 'حجوزات التنظيف';

    protected static string|UnitEnum|null $navigationGroup = 'قسم التنظيف';

    protected static ?int $navigationSort = 2;

    public static function getNavigationTooltip(): ?string
    {
        return 'عرض وإدارة جميع حجوزات التنظيف: رقم الحجز، العميل، العامل، التاريخ والوقت، الحالة، السعر الإجمالي، وتعيين عامل أو إلغاء.';
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

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningBookings::route('/'),
            'view' => ViewCleaningBooking::route('/{record}'),
        ];
    }
}
