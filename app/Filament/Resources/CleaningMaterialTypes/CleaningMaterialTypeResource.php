<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningMaterialTypes;

use App\Filament\Resources\CleaningMaterialTypes\Pages\CreateCleaningMaterialType;
use App\Filament\Resources\CleaningMaterialTypes\Pages\EditCleaningMaterialType;
use App\Filament\Resources\CleaningMaterialTypes\Pages\ListCleaningMaterialTypes;
use BackedEnum;
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
    protected static ?int $navigationSort = 26;
    public static function getNavigationGroup(): ?string { return __('cleaning_admin.nav_groups.settings'); }
    public static function getNavigationLabel(): string { return 'Material Types'; }
    public static function form(Schema $schema): Schema { return $schema->components([TextInput::make('name')->required(),Select::make('cleaning_material_unit_id')->label('Unit')->relationship('unit','name')->searchable()->preload()->required(),TextInput::make('price_per_unit')->numeric()->minValue(0)->required(),Toggle::make('is_active')->default(true)]); }
    public static function table(Table $table): Table { return $table->columns([TextColumn::make('name')->searchable()->sortable(),TextColumn::make('unit.name')->label('Unit'),TextColumn::make('price_per_unit')->money(config('app.currency','SYP'))->sortable(),IconColumn::make('is_active')->boolean()])->defaultSort('name'); }
    public static function getPages(): array { return ['index'=>ListCleaningMaterialTypes::route('/'),'create'=>CreateCleaningMaterialType::route('/create'),'edit'=>EditCleaningMaterialType::route('/{record}/edit')]; }
    public static function canViewAny(): bool { return self::allowed('pricing.view'); }
    public static function canCreate(): bool { return self::allowed('pricing.create'); }
    public static function canEdit(Model $record): bool { return self::allowed('pricing.update'); }
    public static function canDelete(Model $record): bool { return self::allowed('pricing.delete'); }
    private static function allowed(string $permission): bool { $user=auth()->user(); return $user!==null && ($user->hasAnyRole(['admin','Super Admin']) || $user->can($permission)); }
}
