<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningScheduleChangeRequests;

use App\Filament\Resources\CleaningScheduleChangeRequests\Pages\ListCleaningScheduleChangeRequests;
use BackedEnum;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningScheduleChangeRequest;

final class CleaningScheduleChangeRequestResource extends Resource
{
    protected static ?string $model = CleaningScheduleChangeRequest::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentCheck;

    protected static ?int $navigationSort = 18;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::cleaning();
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_catalog.materials.schedule_changes');
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_catalog.materials.singulars.schedule_change');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_catalog.materials.schedule_changes');
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking.booking_number')->label(__('cleaning_catalog.materials.fields.booking'))->searchable()->sortable(),
                TextColumn::make('customer.name')->label(__('cleaning_catalog.materials.fields.customer'))->searchable(),
                TextColumn::make('change_type')->label(__('cleaning_catalog.materials.fields.change_type'))->badge()->formatStateUsing(fn (string $state): string => __('cleaning_catalog.materials.change_types.'.$state)),
                TextColumn::make('status')->label(__('cleaning_catalog.materials.fields.status'))->badge()->formatStateUsing(fn (string $state): string => __('cleaning_catalog.materials.statuses.'.$state))->sortable(),
                TextColumn::make('price_delta')->label(__('cleaning_catalog.materials.fields.price_delta'))->money(config('app.currency', 'SYP'))->sortable(),
                TextColumn::make('decisions_count')->counts('decisions')->label(__('cleaning_catalog.materials.fields.workers')),
                TextColumn::make('created_at')->label(__('cleaning_catalog.materials.fields.created_at'))->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('cleaning_catalog.materials.fields.status'))
                    ->options([
                        'pending' => __('cleaning_catalog.materials.statuses.pending'),
                        'rejected' => __('cleaning_catalog.materials.statuses.rejected'),
                        'applied' => __('cleaning_catalog.materials.statuses.applied'),
                        'resolved' => __('cleaning_catalog.materials.statuses.resolved'),
                        'cancelled' => __('cleaning_catalog.materials.statuses.cancelled'),
                    ]),
            ])
            ->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListCleaningScheduleChangeRequests::route('/')];
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
