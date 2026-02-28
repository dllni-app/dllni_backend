<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings;

use App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\Pages\ListCleaningTimeWarnings;
use App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\Pages\ViewCleaningTimeWarning;
use App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\Schemas\CleaningTimeWarningForm;
use App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\Schemas\CleaningTimeWarningInfolist;
use App\Filament\CleaningAdmin\Resources\CleaningTimeWarnings\Tables\CleaningTimeWarningsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\Cleaning\Models\CleaningTimeWarning;
use UnitEnum;

final class CleaningTimeWarningResource extends Resource
{
    protected static ?string $model = CleaningTimeWarning::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClock;

    protected static ?string $navigationLabel = 'تنبيهات انتهاء الوقت';

    protected static string|UnitEnum|null $navigationGroup = 'قسم التنظيف';

    protected static ?int $navigationSort = 8;

    public static function getNavigationTooltip(): ?string
    {
        return 'سجل تنبيهات انتهاء الوقت: رقم الحجز، نوع الحجز (تنظيف/مناسبة)، وقت الإرسال، رد العميل (تمديد/التزام/إنهاء مبكر)، رد العامل.';
    }

    public static function form(Schema $schema): Schema
    {
        return CleaningTimeWarningForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return CleaningTimeWarningInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return CleaningTimeWarningsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningTimeWarnings::route('/'),
            'view' => ViewCleaningTimeWarning::route('/{record}'),
        ];
    }
}
