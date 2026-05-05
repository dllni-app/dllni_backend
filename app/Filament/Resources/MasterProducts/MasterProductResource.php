<?php

declare(strict_types=1);

namespace App\Filament\Resources\MasterProducts;

use App\Filament\Concerns\ResolvesSupermarketNavigationGroup;
use App\Filament\Resources\MasterProducts\Pages\CreateMasterProduct;
use App\Filament\Resources\MasterProducts\Pages\EditMasterProduct;
use App\Filament\Resources\MasterProducts\Pages\ListMasterProducts;
use App\Filament\Resources\MasterProducts\Pages\ViewMasterProduct;
use App\Filament\Resources\MasterProducts\Schemas\MasterProductForm;
use App\Filament\Resources\MasterProducts\Schemas\MasterProductInfolist;
use App\Filament\Resources\MasterProducts\Tables\MasterProductsTable;
use App\Models\MasterProduct;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

final class MasterProductResource extends Resource
{
    use ResolvesSupermarketNavigationGroup;

    protected static ?string $model = MasterProduct::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCube;

    protected static ?string $navigationLabel = null;

    protected static ?int $navigationSort = 7;

    protected static bool $shouldRegisterNavigation = true;

    public static function getNavigationLabel(): string
    {
        return __('supermarket_admin.master_products');
    }

    public static function getNavigationTooltip(): ?string
    {
        return __('supermarket_admin.tooltips.master_products');
    }

    public static function form(Schema $schema): Schema
    {
        return MasterProductForm::configure($schema);
    }

    public static function infolist(Schema $schema): Schema
    {
        return MasterProductInfolist::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return MasterProductsTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListMasterProducts::route('/'),
            'create' => CreateMasterProduct::route('/create'),
            'view' => ViewMasterProduct::route('/{record}'),
            'edit' => EditMasterProduct::route('/{record}/edit'),
        ];
    }

    public static function getRecordTitle(?Model $record): ?string
    {
        if (! $record instanceof MasterProduct) {
            return null;
        }

        return $record->name;
    }
}
