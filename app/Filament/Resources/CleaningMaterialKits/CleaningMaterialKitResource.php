<?php

declare(strict_types=1);
namespace App\Filament\Resources\CleaningMaterialKits;
use App\Filament\Resources\CleaningMaterialKits\Pages\EditCleaningMaterialKit;
use App\Filament\Resources\CleaningMaterialKits\Pages\ListCleaningMaterialKits;
use BackedEnum; use Filament\Forms\Components\DateTimePicker; use Filament\Forms\Components\Select; use Filament\Forms\Components\Textarea; use Filament\Resources\Resource; use Filament\Schemas\Schema; use Filament\Support\Icons\Heroicon; use Filament\Tables\Columns\TextColumn; use Filament\Tables\Filters\SelectFilter; use Filament\Tables\Table; use Illuminate\Database\Eloquent\Model; use Modules\Cleaning\Models\CleaningBookingMaterialKit;
final class CleaningMaterialKitResource extends Resource
{
    protected static ?string $model=CleaningBookingMaterialKit::class; protected static string|BackedEnum|null $navigationIcon=Heroicon::OutlinedArchiveBox; protected static ?int $navigationSort=24;
    public static function getNavigationGroup(): ?string { return __('cleaning_admin.nav_groups.operations'); } public static function getNavigationLabel(): string { return 'Material Kits'; }
    public static function form(Schema $schema): Schema { return $schema->components([Select::make('status')->required()->options(['preparing'=>'Preparing','ready'=>'Ready','received'=>'Received']),DateTimePicker::make('prepared_at'),Textarea::make('notes')->maxLength(2000)->columnSpanFull()]); }
    public static function table(Table $table): Table { return $table->columns([TextColumn::make('booking.booking_number')->label('Booking')->searchable()->sortable(),TextColumn::make('status')->badge()->sortable(),TextColumn::make('prepared_at')->dateTime()->sortable(),TextColumn::make('receivedByWorker.user.name')->label('Received by')->placeholder('—'),TextColumn::make('received_at')->dateTime()->placeholder('—')])->filters([SelectFilter::make('status')->options(['preparing'=>'Preparing','ready'=>'Ready','received'=>'Received'])])->defaultSort('created_at','desc'); }
    public static function getPages(): array { return ['index'=>ListCleaningMaterialKits::route('/'),'edit'=>EditCleaningMaterialKit::route('/{record}/edit')]; }
    public static function canViewAny(): bool { return self::allowed('cleaning_bookings.view'); } public static function canEdit(Model $record): bool { return self::allowed('cleaning_bookings.update'); } public static function canCreate(): bool { return false; } public static function canDelete(Model $record): bool { return false; } private static function allowed(string $permission): bool { $user=auth()->user(); return $user!==null && ($user->hasAnyRole(['admin','Super Admin']) || $user->can($permission)); }
}
