<?php

declare(strict_types=1);

namespace App\Filament\Resources\SmProducts;

use App\Filament\Resources\SmProducts\Pages\EditSmProduct;
use App\Filament\Resources\SmProducts\Pages\ListSmProducts;
use App\Filament\Resources\SmProducts\Pages\ViewSmProduct;
use App\Filament\Resources\SmProducts\Schemas\SmProductForm;
use App\Filament\Resources\SmProducts\Schemas\SmProductInfolist;
use App\Filament\Resources\SmProducts\Tables\SmProductsTable;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Modules\Supermarket\Models\SmProduct;
use UnitEnum;

final class SmProductResource extends Resource
{
    protected static ?string $model = SmProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = null;

    protected static string|UnitEnum|null $navigationGroup = 'قسم المتاجر';

    protected static ?int $navigationSort = 5;

    public static function getNavigationLabel(): string
    {
        return __('supermarket_admin.products');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('supermarket_admin.tooltips.products');
    }

    public static function form(Schema $schema): Schema
    {
        return SmProductForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return SmProductInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return SmProductsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSmProducts::route('/'),
            'view' => ViewSmProduct::route('/{record}'),
            'edit' => EditSmProduct::route('/{record}/edit'),
        ];
    }

    public static function canCreate(): bool
    {
        return false;
    }
}
