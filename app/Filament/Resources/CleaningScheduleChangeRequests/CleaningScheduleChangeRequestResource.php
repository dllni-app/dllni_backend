<?php

declare(strict_types=1);
namespace App\Filament\Resources\CleaningScheduleChangeRequests;
use App\Filament\Resources\CleaningScheduleChangeRequests\Pages\ListCleaningScheduleChangeRequests; use BackedEnum; use Filament\Resources\Resource; use Filament\Support\Icons\Heroicon; use Filament\Tables\Columns\TextColumn; use Filament\Tables\Filters\SelectFilter; use Filament\Tables\Table; use Illuminate\Database\Eloquent\Model; use Modules\Cleaning\Models\CleaningScheduleChangeRequest;
final class CleaningScheduleChangeRequestResource extends Resource
{
    protected static ?string $model=CleaningScheduleChangeRequest::class; protected static string|BackedEnum|null $navigationIcon=Heroicon::OutlinedClipboardDocumentCheck; protected static ?int $navigationSort=25;
    public static function getNavigationGroup(): ?string { return __('cleaning_admin.nav_groups.operations'); } public static function getNavigationLabel(): string { return 'Schedule Change Approvals'; }
    public static function table(Table $table): Table { return $table->columns([TextColumn::make('booking.booking_number')->label('Booking')->searchable()->sortable(),TextColumn::make('customer.name')->label('Customer')->searchable(),TextColumn::make('change_type')->badge(),TextColumn::make('status')->badge()->sortable(),TextColumn::make('price_delta')->money(config('app.currency','SYP'))->sortable(),TextColumn::make('decisions_count')->counts('decisions')->label('Workers'),TextColumn::make('created_at')->dateTime()->sortable()])->filters([SelectFilter::make('status')->options(['pending'=>'Pending','rejected'=>'Rejected','applied'=>'Applied','resolved'=>'Resolved','cancelled'=>'Cancelled'])])->defaultSort('created_at','desc'); }
    public static function getPages(): array { return ['index'=>ListCleaningScheduleChangeRequests::route('/')]; }
    public static function canViewAny(): bool { return self::allowed('cleaning_bookings.view'); } public static function canCreate(): bool { return false; } public static function canEdit(Model $record): bool { return false; } public static function canDelete(Model $record): bool { return false; } private static function allowed(string $permission): bool { $user=auth()->user(); return $user!==null && ($user->hasAnyRole(['admin','Super Admin']) || $user->can($permission)); }
}
