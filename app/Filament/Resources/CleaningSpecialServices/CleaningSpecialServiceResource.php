<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningSpecialServices;

use App\Filament\Resources\CleaningSpecialServices\Pages\CreateCleaningSpecialService;
use App\Filament\Resources\CleaningSpecialServices\Pages\EditCleaningSpecialService;
use App\Filament\Resources\CleaningSpecialServices\Pages\ListCleaningSpecialServices;
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
use Modules\Cleaning\Models\CleaningSpecialService;

final class CleaningSpecialServiceResource extends Resource
{
    protected static ?string $model=CleaningSpecialService::class;
    protected static string|BackedEnum|null $navigationIcon=Heroicon::OutlinedSparkles;
    protected static ?int $navigationSort=23;
    public static function getNavigationGroup(): ?string { return __('cleaning_admin.nav_groups.operations'); }
    public static function getNavigationLabel(): string { return 'Special Cleaning Services'; }
    public static function form(Schema $schema): Schema { return $schema->components([
        TextInput::make('name')->required()->maxLength(255),
        TextInput::make('image_url')->label('Image URL')->url()->maxLength(2048),
        Select::make('pricing_unit')->required()->options(['piece'=>'Piece','sqm'=>'Square meter','linear_meter'=>'Linear meter','device'=>'Device','sofa'=>'Sofa','chair'=>'Chair','carpet'=>'Carpet','solar_panel'=>'Solar panel']),
        TextInput::make('base_unit_price')->numeric()->minValue(0)->required(),
        Toggle::make('is_active')->default(true),
        Select::make('equipment')->relationship('equipment','name')->multiple()->searchable()->preload()->label('Required Equipment'),
        Repeater::make('dirtinessRules')->relationship()->label('Dirtiness Rules')->schema([TextInput::make('dirtiness_level')->required()->maxLength(32),TextInput::make('price_multiplier')->numeric()->minValue(0.001)->required()->default(1),Toggle::make('is_active')->default(true)])->columns(3),
    ])->columns(2); }
    public static function table(Table $table): Table { return $table->columns([TextColumn::make('name')->searchable()->sortable(),TextColumn::make('pricing_unit')->badge()->sortable(),TextColumn::make('base_unit_price')->money(config('app.currency','SYP'))->sortable(),TextColumn::make('equipment.name')->label('Equipment')->listWithLineBreaks()->limitList(3)->toggleable(),IconColumn::make('is_active')->boolean()])->filters([TernaryFilter::make('is_active')])->defaultSort('name'); }
    public static function getPages(): array { return ['index'=>ListCleaningSpecialServices::route('/'),'create'=>CreateCleaningSpecialService::route('/create'),'edit'=>EditCleaningSpecialService::route('/{record}/edit')]; }
    public static function canViewAny(): bool { return self::allowed('pricing.view'); }
    public static function canCreate(): bool { return self::allowed('pricing.create'); }
    public static function canEdit(Model $record): bool { return self::allowed('pricing.update'); }
    public static function canDelete(Model $record): bool { return self::allowed('pricing.delete'); }
    private static function allowed(string $permission): bool { $user=auth()->user(); return $user!==null && ($user->hasAnyRole(['admin','Super Admin']) || $user->can($permission)); }
}
