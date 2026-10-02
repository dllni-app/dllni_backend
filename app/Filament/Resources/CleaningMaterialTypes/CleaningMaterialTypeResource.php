<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningMaterialTypes;

use App\Filament\Resources\CleaningMaterialTypes\Pages\CreateCleaningMaterialType;
use App\Filament\Resources\CleaningMaterialTypes\Pages\EditCleaningMaterialType;
use App\Filament\Resources\CleaningMaterialTypes\Pages\ListCleaningMaterialTypes;
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
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningMaterialType;

final class CleaningMaterialTypeResource extends Resource
{
    protected static ?string $model = CleaningMaterialType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 27;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::cleaning();
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_catalog.materials.types');
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_catalog.materials.singulars.material_type');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_catalog.materials.types');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('cleaning_catalog.materials.fields.name'))->required(),
            Select::make('cleaning_material_unit_id')->label(__('cleaning_catalog.materials.fields.unit'))->relationship('unit', 'name')->searchable()->preload()->required(),
            TextInput::make('price_per_unit')->label(__('cleaning_catalog.materials.fields.price_per_unit'))->numeric()->minValue(0)->required(),
            Toggle::make('is_active')->label(__('cleaning_catalog.materials.fields.is_active'))->default(true),
            Repeater::make('quantityRules')
                ->relationship()
                ->label(__('cleaning_catalog.materials.fields.room_quantity_rules'))
                ->schema([
                    Select::make('room_type')->label(__('cleaning_catalog.materials.fields.room_type'))->options([
                        'bedroom' => __('cleaning_catalog.materials.options.bedroom'),
                        'bathroom' => __('cleaning_catalog.materials.options.bathroom'),
                        'kitchen' => __('cleaning_catalog.materials.options.kitchen'),
                        'living_room' => __('cleaning_catalog.materials.options.living_room'),
                        'balcony' => __('cleaning_catalog.materials.options.balcony'),
                        'hall' => __('cleaning_catalog.materials.options.hall'),
                        'corridor' => __('cleaning_catalog.materials.options.corridor'),
                        'other' => __('cleaning_catalog.materials.options.other'),
                    ])->nullable(),
                    Select::make('room_size')->label(__('cleaning_catalog.materials.fields.room_size'))->options([
                        'small' => __('cleaning_catalog.materials.options.small'),
                        'medium' => __('cleaning_catalog.materials.options.medium'),
                        'large' => __('cleaning_catalog.materials.options.large'),
                    ])->nullable(),
                    Select::make('cleaning_mode')->label(__('cleaning_catalog.materials.fields.cleaning_mode'))->options([
                        'regular' => __('cleaning_catalog.materials.options.regular'),
                        'deep' => __('cleaning_catalog.materials.options.deep'),
                    ])->nullable(),
                    TextInput::make('quantity_per_room')->label(__('cleaning_catalog.materials.fields.quantity_per_room'))->numeric()->minValue(0.001)->required(),
                    Toggle::make('is_active')->label(__('cleaning_catalog.materials.fields.is_active'))->default(true),
                    Toggle::make('requires_admin_resolution')->label(__('cleaning_catalog.materials.fields.migration_review'))->disabled(),
                ])
                ->columns(3)
                ->collapsible()
                ->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('cleaning_catalog.materials.fields.name'))->searchable()->sortable(),
            TextColumn::make('unit.name')->label(__('cleaning_catalog.materials.fields.unit')),
            TextColumn::make('price_per_unit')->label(__('cleaning_catalog.materials.fields.price_per_unit'))->money(config('app.currency', 'SYP'))->sortable(),
            IconColumn::make('is_active')->label(__('cleaning_catalog.materials.fields.is_active'))->boolean(),
        ])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ListCleaningMaterialTypes::route('/'), 'create' => CreateCleaningMaterialType::route('/create'), 'edit' => EditCleaningMaterialType::route('/{record}/edit')];
    }

    public static function canViewAny(): bool
    {
        return self::allowed('pricing.view');
    }

    public static function canCreate(): bool
    {
        return self::allowed('pricing.create');
    }

    public static function canEdit(Model $record): bool
    {
        return self::allowed('pricing.update');
    }

    public static function canDelete(Model $record): bool
    {
        return self::allowed('pricing.delete');
    }

    private static function allowed(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->hasAnyRole(['admin', 'Super Admin']) || $user->can($permission));
    }
}
