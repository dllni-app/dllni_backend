<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningMaterials;

use App\Filament\Resources\CleaningMaterials\Pages\CreateCleaningMaterial;
use App\Filament\Resources\CleaningMaterials\Pages\EditCleaningMaterial;
use App\Filament\Resources\CleaningMaterials\Pages\ListCleaningMaterials;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningMaterial;

final class CleaningMaterialResource extends Resource
{
    protected static ?string $model = CleaningMaterial::class;
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedBeaker;
    protected static ?int $navigationSort = 24;

    public static function getNavigationGroup(): ?string { return __('cleaning_admin.nav_groups.operations'); }
    public static function getNavigationLabel(): string { return 'Cleaning Materials'; }
    public static function getModelLabel(): string { return 'Cleaning Material'; }
    public static function getPluralModelLabel(): string { return 'Cleaning Materials'; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Select::make('cleaning_material_type_id')->label('Material Type')->relationship('materialType', 'name')->searchable()->preload()->required(),
            TextInput::make('stock_quantity')->label('Current Stock')->numeric()->minValue(0)->required()->suffix('configured unit'),
            TextInput::make('low_stock_threshold')->label('Low-stock Threshold')->numeric()->minValue(0)->required(),
            Toggle::make('is_active')->default(true),
            Repeater::make('quantityRules')->relationship()->label('Quantity Rules')->schema([
                Select::make('room_type')->options(['bedroom'=>'Bedroom','bathroom'=>'Bathroom','kitchen'=>'Kitchen','living_room'=>'Living Room','balcony'=>'Balcony','hall'=>'Hall','corridor'=>'Corridor','other'=>'Other'])->nullable(),
                Select::make('room_size')->options(['small'=>'Small','medium'=>'Medium','large'=>'Large'])->nullable(),
                Select::make('cleaning_mode')->options(['regular'=>'Regular','deep'=>'Deep'])->nullable(),
                TextInput::make('quantity_per_room')->numeric()->minValue(0.001)->required(),
                Toggle::make('is_active')->default(true),
            ])->columns(2)->collapsible(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('materialType.name')->label('Type')->searchable(),
            TextColumn::make('materialType.unit.symbol')->label('Unit')->placeholder('—'),
            TextColumn::make('stock_quantity')->label('Stock')->numeric(decimalPlaces: 3)->sortable(),
            TextColumn::make('low_stock_threshold')->label('Low-stock At')->numeric(decimalPlaces: 3)->toggleable(),
            IconColumn::make('is_low_stock')->label('Low Stock')->boolean()->state(fn (CleaningMaterial $record): bool => $record->isLowStock()),
            IconColumn::make('is_active')->boolean(),
            TextColumn::make('updated_at')->dateTime()->sortable()->toggleable(isToggledHiddenByDefault: true),
        ])->filters([TernaryFilter::make('is_active')])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index'=>ListCleaningMaterials::route('/'),'create'=>CreateCleaningMaterial::route('/create'),'edit'=>EditCleaningMaterial::route('/{record}/edit')];
    }

    public static function canViewAny(): bool { return self::allowed('pricing.view'); }
    public static function canCreate(): bool { return self::allowed('pricing.create'); }
    public static function canEdit(Model $record): bool { return self::allowed('pricing.update'); }
    public static function canDelete(Model $record): bool { return self::allowed('pricing.delete'); }

    private static function allowed(string $permission): bool
    {
        $user = auth()->user();
        return $user !== null && ($user->hasAnyRole(['admin', 'Super Admin']) || $user->can($permission));
    }
}
