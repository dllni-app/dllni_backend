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
    protected static ?string $model = CleaningMaterialInventoryMovement::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArrowsRightLeft;

    protected static ?int $navigationSort = 16;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::cleaning();
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_catalog.materials.movements');
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_catalog.materials.singulars.movement');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_catalog.materials.movements');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('material.name')->label(__('cleaning_catalog.materials.fields.material'))->searchable(),
                TextColumn::make('movement_type')->label(__('cleaning_catalog.materials.fields.movement_type'))->badge()->formatStateUsing(fn (string $state): string => __('cleaning_catalog.materials.statuses.'.$state))->sortable(),
                TextColumn::make('quantity_delta')->label(__('cleaning_catalog.materials.fields.quantity_delta'))->numeric(decimalPlaces: 3),
                TextColumn::make('reference_id')->label(__('cleaning_catalog.materials.fields.reference')),
                TextColumn::make('created_at')->label(__('cleaning_catalog.materials.fields.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('movement_type')
                    ->label(__('cleaning_catalog.materials.fields.movement_type'))
                    ->options([
                        'reserved' => __('cleaning_catalog.materials.statuses.reserved'),
                        'released' => __('cleaning_catalog.materials.statuses.released'),
                        'consumed' => __('cleaning_catalog.materials.statuses.consumed'),
                        'adjusted' => __('cleaning_catalog.materials.statuses.adjusted'),
                    ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListCleaningMaterialMovements::route('/')];
    }

    public static function canViewAny(): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->hasAnyRole(['admin', 'Super Admin']) || $user->can('pricing.view'));
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }
}
