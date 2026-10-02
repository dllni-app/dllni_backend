<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningEquipmentReservations;

use App\Filament\Resources\CleaningEquipmentReservations\Pages\ListCleaningEquipmentReservations;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningEquipmentReservation;

final class CleaningEquipmentReservationResource extends Resource
{
    protected static ?string $model = CleaningEquipmentReservation::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 15;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::cleaning();
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_catalog.materials.equipment_reservations');
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_catalog.materials.singulars.equipment_reservation');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_catalog.materials.equipment_reservations');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('equipment.asset_code')->label(__('cleaning_catalog.materials.fields.asset'))->searchable(), TextColumn::make('equipment.name')->label(__('cleaning_catalog.materials.fields.equipment'))->searchable(), TextColumn::make('bookingSpecialService.booking.booking_number')->label(__('cleaning_catalog.materials.fields.booking'))->searchable(), TextColumn::make('worker.user.name')->label(__('cleaning_catalog.materials.fields.workers'))->placeholder('—'), TextColumn::make('reserved_from')->label(__('cleaning_catalog.materials.fields.reserved_from'))->dateTime()->sortable(), TextColumn::make('reserved_until')->label(__('cleaning_catalog.materials.fields.reserved_until'))->dateTime()->sortable(), TextColumn::make('status')->label(__('cleaning_catalog.materials.fields.status'))->badge()->formatStateUsing(fn (string $state): string => __('cleaning_catalog.materials.statuses.'.$state))->sortable(), TextColumn::make('failure_reason')->label(__('cleaning_catalog.materials.fields.failure_reason'))->limit(40)->placeholder('—')])->filters([SelectFilter::make('status')->label(__('cleaning_catalog.materials.fields.status'))->options(['reserved' => __('cleaning_catalog.materials.statuses.reserved'), 'handed_over' => __('cleaning_catalog.materials.statuses.handed_over'), 'acknowledged' => __('cleaning_catalog.materials.statuses.acknowledged'), 'returned' => __('cleaning_catalog.materials.statuses.returned'), 'failed' => __('cleaning_catalog.materials.statuses.failed')])])->defaultSort('reserved_from', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListCleaningEquipmentReservations::route('/')];
    }

    public static function canViewAny(): bool
    {
        return self::allowed('cleaning_bookings.view');
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

    private static function allowed(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->hasAnyRole(['admin', 'Super Admin']) || $user->can($permission));
    }
}
