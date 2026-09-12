<?php

declare(strict_types=1);
namespace App\Filament\Resources\CleaningDirtinessLevels;
use App\Filament\Resources\CleaningDirtinessLevels\Pages\CreateCleaningDirtinessLevel;
use App\Filament\Resources\CleaningDirtinessLevels\Pages\EditCleaningDirtinessLevel;
use App\Filament\Resources\CleaningDirtinessLevels\Pages\ListCleaningDirtinessLevels;
use BackedEnum; use Filament\Forms\Components\TextInput; use Filament\Forms\Components\Toggle; use Filament\Resources\Resource; use Filament\Schemas\Schema; use Filament\Support\Icons\Heroicon; use Filament\Tables\Columns\IconColumn; use Filament\Tables\Columns\TextColumn; use Filament\Tables\Table; use Illuminate\Database\Eloquent\Model; use Modules\Cleaning\Models\CleaningDirtinessLevel;
final class CleaningDirtinessLevelResource extends Resource
{
    protected static ?string $model=CleaningDirtinessLevel::class; protected static string|BackedEnum|null $navigationIcon=Heroicon::OutlinedAdjustmentsHorizontal; protected static ?int $navigationSort=31;
    public static function getNavigationGroup(): ?string { return __('cleaning_admin.nav_groups.settings'); } public static function getNavigationLabel(): string { return 'Dirtiness Levels'; }
    public static function form(Schema $schema): Schema { return $schema->components([TextInput::make('name')->required(),TextInput::make('slug')->required()->unique(ignoreRecord:true),TextInput::make('price_multiplier')->numeric()->minValue(0.001)->required()->default(1),TextInput::make('sort_order')->numeric()->minValue(0)->default(0),Toggle::make('is_active')->default(true)]); }
    public static function table(Table $table): Table { return $table->columns([TextColumn::make('name')->searchable()->sortable(),TextColumn::make('slug'),TextColumn::make('price_multiplier')->numeric(decimalPlaces:3)->sortable(),TextColumn::make('sort_order')->sortable(),IconColumn::make('is_active')->boolean()])->defaultSort('sort_order'); }
    public static function getPages(): array { return ['index'=>ListCleaningDirtinessLevels::route('/'),'create'=>CreateCleaningDirtinessLevel::route('/create'),'edit'=>EditCleaningDirtinessLevel::route('/{record}/edit')]; }
    public static function canViewAny(): bool { return self::allowed('pricing.view'); } public static function canCreate(): bool { return self::allowed('pricing.create'); } public static function canEdit(Model $record): bool { return self::allowed('pricing.update'); } private static function allowed(string $permission): bool { $user=auth()->user(); return $user!==null && ($user->hasAnyRole(['admin','Super Admin']) || $user->can($permission)); }
}
