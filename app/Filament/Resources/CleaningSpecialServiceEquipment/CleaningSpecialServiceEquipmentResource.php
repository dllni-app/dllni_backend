<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningSpecialServiceEquipment;

use App\Filament\Clusters\CleaningCatalogCluster;
use App\Filament\Resources\CleaningSpecialServiceEquipment\Pages\CreateCleaningSpecialServiceEquipment;
use App\Filament\Resources\CleaningSpecialServiceEquipment\Pages\EditCleaningSpecialServiceEquipment;
use App\Filament\Resources\CleaningSpecialServiceEquipment\Pages\ListCleaningSpecialServiceEquipment;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningBooking;
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;
use Modules\Cleaning\Services\CleaningEquipmentHandoverService;

final class CleaningSpecialServiceEquipmentResource extends Resource
{
    protected static ?string $model = CleaningSpecialServiceEquipment::class;

    protected static ?string $cluster = CleaningCatalogCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    protected static ?int $navigationSort = 30;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::cleaning();
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_catalog.materials.equipment');
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_catalog.materials.singulars.equipment');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_catalog.materials.equipment');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('cleaning_catalog.materials.fields.name'))->required(),
            TextInput::make('asset_code')->label(__('cleaning_catalog.materials.fields.asset'))->unique(ignoreRecord: true)->maxLength(64),
            Select::make('status')->label(__('cleaning_catalog.materials.fields.status'))->required()->options([
                'available' => __('cleaning_catalog.materials.statuses.available'),
                'reserved' => __('cleaning_catalog.materials.statuses.reserved'),
                'handed_over' => __('cleaning_catalog.materials.statuses.handed_over'),
                'in_use' => 'قيد الاستخدام',
                'maintenance' => __('cleaning_catalog.materials.statuses.maintenance'),
                'broken' => __('cleaning_catalog.materials.statuses.broken'),
                'lost' => 'مفقودة',
            ])->default('available')->disabled(fn (?CleaningSpecialServiceEquipment $record): bool => in_array($record?->status, ['in_use', 'lost'], true)),
            TextInput::make('buffer_before_minutes')->label(__('cleaning_catalog.materials.fields.buffer_before'))->numeric()->minValue(0)->default(0),
            TextInput::make('buffer_after_minutes')->label(__('cleaning_catalog.materials.fields.buffer_after'))->numeric()->minValue(0)->default(0),
            Toggle::make('is_active')->label(__('cleaning_catalog.materials.fields.is_active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('cleaning_catalog.materials.fields.name'))->searchable()->sortable(),
            TextColumn::make('asset_code')->label(__('cleaning_catalog.materials.fields.asset'))->searchable(),
            TextColumn::make('status')->label(__('cleaning_catalog.materials.fields.status'))->badge()->formatStateUsing(fn (string $state): string => __('cleaning_catalog.materials.statuses.'.$state))->sortable(),
            TextColumn::make('buffer_before_minutes')->label(__('cleaning_catalog.materials.fields.buffer_before'))->suffix(' '.__('cleaning_catalog.materials.fields.minutes')),
            TextColumn::make('buffer_after_minutes')->label(__('cleaning_catalog.materials.fields.buffer_after'))->suffix(' '.__('cleaning_catalog.materials.fields.minutes')),
            IconColumn::make('is_active')->label(__('cleaning_catalog.materials.fields.is_active'))->boolean(),
        ])->recordActions([
            Action::make('checkout')
                ->label('صرف المعدة للطلب')
                ->icon('heroicon-o-arrow-up-tray')
                ->color('info')
                ->visible(fn (CleaningSpecialServiceEquipment $record): bool => self::allowed('bookings.update') && $record->is_active && $record->status === 'available')
                ->form([
                    Select::make('booking_id')->label('الطلب')
                        ->required()->searchable()
                        ->getSearchResultsUsing(fn (string $search): array => CleaningBooking::query()
                            ->where('booking_number', 'like', '%'.$search.'%')
                            ->latest('id')->limit(30)->pluck('booking_number', 'id')->all())
                        ->getOptionLabelUsing(fn ($value): ?string => CleaningBooking::query()->whereKey($value)->value('booking_number')),
                    Textarea::make('note')->label('ملاحظات العهدة')->required()->maxLength(2000),
                ])
                ->action(function (CleaningSpecialServiceEquipment $record, array $data): void {
                    app(CleaningEquipmentHandoverService::class)->checkoutForBooking(
                        CleaningBooking::query()->findOrFail($data['booking_id']), $record, auth()->user(), $data['note']
                    );
                    Notification::make()->title('تم تسجيل صرف المعدة والعهدة')->success()->send();
                }),
            Action::make('recover')
                ->label('إعادة المعدة المفقودة للمخزون')
                ->icon('heroicon-o-arrow-path')
                ->color('warning')
                ->visible(fn (CleaningSpecialServiceEquipment $record): bool => self::allowed('bookings.update') && $record->status === 'lost')
                ->requiresConfirmation()
                ->form([Textarea::make('note')->label('محضر العثور والفحص')->required()->maxLength(2000)])
                ->action(function (CleaningSpecialServiceEquipment $record, array $data): void {
                    app(CleaningEquipmentHandoverService::class)->recoverLostEquipment($record, auth()->user(), $data['note']);
                    Notification::make()->title('تمت إعادة المعدة بعد توثيق العثور عليها')->success()->send();
                }),
        ])->defaultSort('name');
    }

    public static function getPages(): array
    {
        return ['index' => ListCleaningSpecialServiceEquipment::route('/'), 'create' => CreateCleaningSpecialServiceEquipment::route('/create'), 'edit' => EditCleaningSpecialServiceEquipment::route('/{record}/edit')];
    }

    public static function canViewAny(): bool
    {
        return self::allowed('pricing.view');
    }

    public static function canCreate(): bool
    {
        return self::allowed('pricing.create');
    }

    public static function canEdit(Model $record): bool
    {
        return self::allowed('pricing.update');
    }

    public static function canDelete(Model $record): bool
    {
        return self::allowed('pricing.delete');
    }

    private static function allowed(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->hasAnyRole(['admin', 'Super Admin']) || $user->can($permission));
    }
}
