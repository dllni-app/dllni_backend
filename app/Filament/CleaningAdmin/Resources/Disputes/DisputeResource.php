<?php

declare(strict_types=1);

namespace App\Filament\CleaningAdmin\Resources\Disputes;

use App\Filament\CleaningAdmin\Resources\Disputes\Pages\CreateDispute;
use App\Filament\CleaningAdmin\Resources\Disputes\Pages\EditDispute;
use App\Filament\CleaningAdmin\Resources\Disputes\Pages\ListDisputes;
use App\Filament\CleaningAdmin\Resources\Disputes\Pages\ViewDispute;
use App\Filament\CleaningAdmin\Resources\Disputes\Schemas\DisputeForm;
use App\Filament\CleaningAdmin\Resources\Disputes\Schemas\DisputeInfolist;
use App\Filament\CleaningAdmin\Resources\Disputes\Tables\DisputesTable;
use App\Models\Dispute;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use UnitEnum;

final class DisputeResource extends Resource
{
    protected static ?string $model = Dispute::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedExclamationTriangle;

    protected static ?string $navigationLabel = 'النزاعات والشكاوى';

    protected static string|UnitEnum|null $navigationGroup = 'قسم التنظيف';

    protected static ?int $navigationSort = 5;

    public static function getNavigationTooltip(): ?string
    {
        return 'حل النزاعات والشكاوى: رقم النزاع، رقم الحجز، العميل، العامل، السبب، الحالة؛ رد، استرداد جزئي، خصم من العامل، إغلاق.';
    }

    public static function form(Schema $schema): Schema
    {
        return DisputeForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return DisputeInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return DisputesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListDisputes::route('/'),
            'create' => CreateDispute::route('/create'),
            'view' => ViewDispute::route('/{record}'),
            'edit' => EditDispute::route('/{record}/edit'),
        ];
    }
}
