<?php

declare(strict_types=1);
namespace App\Filament\Resources\CleaningEquipmentReservations;
use App\Filament\Resources\CleaningEquipmentReservations\Pages\ListCleaningEquipmentReservations; use BackedEnum; use Filament\Resources\Resource; use Filament\Support\Icons\Heroicon; use Filament\Tables\Columns\TextColumn; use Filament\Tables\Filters\SelectFilter; use Filament\Tables\Table; use Illuminate\Database\Eloquent\Model; use Modules\Cleaning\Models\CleaningEquipmentReservation;
final class CleaningEquipmentReservationResource extends Resource
{
    protected static ?string $model=CleaningEquipmentReservation::class; protected static string|BackedEnum|null $navigationIcon=Heroicon::OutlinedWrenchScrewdriver; protected static ?int $navigationSort=26;
    public static function getNavigationGroup(): ?string { return __('cleaning_admin.nav_groups.operations'); } public static function getNavigationLabel(): string { return 'Equipment Reservations'; }
    public static function table(Table $table): Table { return $table->columns([TextColumn::make('equipment.asset_code')->label('Asset')->searchable(),TextColumn::make('equipment.name')->label('Equipment')->searchable(),TextColumn::make('bookingSpecialService.booking.booking_number')->label('Booking')->searchable(),TextColumn::make('worker.user.name')->label('Worker')->placeholder('—'),TextColumn::make('reserved_from')->dateTime()->sortable(),TextColumn::make('reserved_until')->dateTime()->sortable(),TextColumn::make('status')->badge()->sortable(),TextColumn::make('failure_reason')->limit(40)->placeholder('—')])->filters([SelectFilter::make('status')->options(['reserved'=>'Reserved','handed_over'=>'Handed over','acknowledged'=>'Acknowledged','returned'=>'Returned','failed'=>'Failed'])])->defaultSort('reserved_from','desc'); }
    public static function getPages(): array { return ['index'=>ListCleaningEquipmentReservations::route('/')]; }
    public static function canViewAny(): bool { return self::allowed('cleaning_bookings.view'); } public static function canCreate(): bool { return false; } public static function canEdit(Model $record): bool { return false; } public static function canDelete(Model $record): bool { return false; } private static function allowed(string $permission): bool { $user=auth()->user(); return $user!==null && ($user->hasAnyRole(['admin','Super Admin']) || $user->can($permission)); }
}
