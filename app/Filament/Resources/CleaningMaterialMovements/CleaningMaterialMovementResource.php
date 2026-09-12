<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningMaterialMovements;

use App\Filament\Resources\CleaningMaterialMovements\Pages\ListCleaningMaterialMovements;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningMaterialInventoryMovement;

final class CleaningMaterialMovementResource extends Resource
{
    protected static ?string $model=CleaningMaterialInventoryMovement::class;
    protected static string|BackedEnum|null $navigationIcon=Heroicon::OutlinedArrowsRightLeft;
    protected static ?int $navigationSort=27;
    public static function getNavigationGroup(): ?string { return __('cleaning_admin.nav_groups.operations'); }
    public static function getNavigationLabel(): string { return 'Material Inventory Movements'; }
    public static function table(Table $table): Table { return $table->columns([TextColumn::make('material.name')->label('Material')->searchable(),TextColumn::make('movement_type')->badge()->sortable(),TextColumn::make('quantity_delta')->numeric(decimalPlaces:3),TextColumn::make('reference_id')->label('Booking ID'),TextColumn::make('created_at')->dateTime()->sortable()])->filters([SelectFilter::make('movement_type')->options(['reserved'=>'Reserved','released'=>'Released','consumed'=>'Consumed','adjusted'=>'Adjusted'])])->defaultSort('created_at','desc'); }
    public static function getPages(): array { return ['index'=>ListCleaningMaterialMovements::route('/')]; }
    public static function canViewAny(): bool { $user=auth()->user(); return $user!==null && ($user->hasAnyRole(['admin','Super Admin']) || $user->can('pricing.view')); }
    public static function canCreate(): bool { return false; }
    public static function canEdit(Model $record): bool { return false; }
    public static function canDelete(Model $record): bool { return false; }
}
