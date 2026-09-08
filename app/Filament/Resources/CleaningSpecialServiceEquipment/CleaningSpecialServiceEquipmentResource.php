<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningSpecialServiceEquipment;

use App\Filament\Resources\CleaningSpecialServiceEquipment\Pages\CreateCleaningSpecialServiceEquipment;
use App\Filament\Resources\CleaningSpecialServiceEquipment\Pages\EditCleaningSpecialServiceEquipment;
use App\Filament\Resources\CleaningSpecialServiceEquipment\Pages\ListCleaningSpecialServiceEquipment;
use BackedEnum;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;

final class CleaningSpecialServiceEquipmentResource extends Resource
{
    protected static ?string $model=CleaningSpecialServiceEquipment::class;
    protected static string|BackedEnum|null $navigationIcon=Heroicon::OutlinedWrenchScrewdriver;
    protected static ?int $navigationSort=28;
    public static function getNavigationGroup(): ?string { return __('cleaning_admin.nav_groups.settings'); }
    public static function getNavigationLabel(): string { return 'Special Service Equipment'; }
    public static function form(Schema $schema): Schema { return $schema->components([TextInput::make('name')->required(),Toggle::make('is_active')->default(true)]); }
    public static function table(Table $table): Table { return $table->columns([TextColumn::make('name')->searchable()->sortable(),IconColumn::make('is_active')->boolean()])->defaultSort('name'); }
    public static function getPages(): array { return ['index'=>ListCleaningSpecialServiceEquipment::route('/'),'create'=>CreateCleaningSpecialServiceEquipment::route('/create'),'edit'=>EditCleaningSpecialServiceEquipment::route('/{record}/edit')]; }
    public static function canViewAny(): bool { return self::allowed('pricing.view'); }
    public static function canCreate(): bool { return self::allowed('pricing.create'); }
    public static function canEdit(Model $record): bool { return self::allowed('pricing.update'); }
    public static function canDelete(Model $record): bool { return self::allowed('pricing.delete'); }
    private static function allowed(string $permission): bool { $user=auth()->user(); return $user!==null && ($user->hasAnyRole(['admin','Super Admin']) || $user->can($permission)); }
}
