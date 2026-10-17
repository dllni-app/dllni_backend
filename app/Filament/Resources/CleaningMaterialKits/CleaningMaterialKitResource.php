<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningMaterialKits;

use App\Filament\Clusters\CleaningMaterialsCluster;
use App\Filament\Resources\CleaningMaterialKits\Pages\EditCleaningMaterialKit;
use App\Filament\Resources\CleaningMaterialKits\Pages\ListCleaningMaterialKits;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Hidden;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningBookingMaterial;
use Modules\Cleaning\Models\CleaningBookingMaterialKit;
use Modules\Cleaning\Services\CleaningGroupCustodyPolicy;
use Modules\Cleaning\Services\CleaningMaterialInventoryService;

final class CleaningMaterialKitResource extends Resource
{
    protected static ?string $model = CleaningBookingMaterialKit::class;

    protected static ?string $cluster = CleaningMaterialsCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 14;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::cleaning();
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_catalog.materials.kits');
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_catalog.materials.singulars.material_kit');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_catalog.materials.kits');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('status')
                ->label(__('cleaning_catalog.materials.fields.status'))
                ->required()
                ->options(fn (?CleaningBookingMaterialKit $record): array => [
                    'preparing' => __('cleaning_catalog.materials.statuses.preparing'),
                    'ready' => __('cleaning_catalog.materials.statuses.ready'),
                    ...($record?->status === CleaningBookingMaterialKit::RECEIVED
                        ? ['received' => __('cleaning_catalog.materials.statuses.received')]
                        : []),
                ])->disabled(fn (?CleaningBookingMaterialKit $record): bool => $record?->status === CleaningBookingMaterialKit::RECEIVED),
            DateTimePicker::make('prepared_at')->label(__('cleaning_catalog.materials.fields.prepared_at')),
            Textarea::make('notes')->label(__('cleaning_catalog.materials.fields.notes'))->maxLength(2000)->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('booking.booking_number')->label(__('cleaning_catalog.materials.fields.booking'))->searchable()->sortable(),
                TextColumn::make('status')->label(__('cleaning_catalog.materials.fields.status'))->badge()->formatStateUsing(fn (string $state): string => __('cleaning_catalog.materials.statuses.'.$state))->sortable(),
                TextColumn::make('prepared_at')->label(__('cleaning_catalog.materials.fields.prepared_at'))->dateTime()->sortable(),
                TextColumn::make('receivedByWorker.user.name')->label(__('cleaning_catalog.materials.fields.received_by'))->placeholder('—'),
                TextColumn::make('receivedBySupervisor.name')->label('المشرف المستلم')->placeholder('—'),
                TextColumn::make('received_at')->label(__('cleaning_catalog.materials.fields.received_at'))->dateTime()->placeholder('—'),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->label(__('cleaning_catalog.materials.fields.status'))
                    ->options([
                        'preparing' => __('cleaning_catalog.materials.statuses.preparing'),
                        'ready' => __('cleaning_catalog.materials.statuses.ready'),
                        'received' => __('cleaning_catalog.materials.statuses.received'),
                    ]),
            ])
            ->recordActions([
                Action::make('receive_by_supervisor')
                    ->label('تسليم عهدة المواد للمشرف')
                    ->icon('heroicon-o-user-group')
                    ->visible(fn (CleaningBookingMaterialKit $record): bool => self::allowed('cleaning_bookings.update')
                        && $record->status === CleaningBookingMaterialKit::READY
                        && $record->booking !== null
                        && app(CleaningGroupCustodyPolicy::class)->usesSupervisorCustody($record->booking))
                    ->form([Textarea::make('note')->label('محضر التسليم')->required()->maxLength(2000)])
                    ->action(function (CleaningBookingMaterialKit $record, array $data): void {
                        $supervisor = $record->booking->supervisorAssignments()->whereNull('released_at')->firstOrFail()->supervisor;
                        app(CleaningMaterialInventoryService::class)->receiveKitForSupervisor($record->booking, $supervisor, auth()->user(), $data['note']);
                        Notification::make()->title('تم تسجيل استلام المشرف للمواد')->success()->send();
                    }),
                Action::make('reconcile_usage')
                    ->label('تسوية استخدام المواد')
                    ->icon('heroicon-o-clipboard-document-check')
                    ->visible(fn (CleaningBookingMaterialKit $record): bool => self::allowed('cleaning_bookings.update')
                        && $record->status === CleaningBookingMaterialKit::RECEIVED
                        && $record->booking?->materials()->whereIn('inventory_status', ['reserved', 'consumed'])->exists())
                    ->form([
                        Repeater::make('lines')->label('الكميات المصروفة والمستخدمة')
                            ->schema([
                                Hidden::make('id')->required(),
                                TextInput::make('material')->label('المادة')->disabled()->dehydrated(false),
                                TextInput::make('used')->label('المستخدم')->numeric()->minValue(0)->required(),
                                TextInput::make('returned')->label('المعاد سليماً')->numeric()->minValue(0)->required(),
                                TextInput::make('damaged')->label('التالف')->numeric()->minValue(0)->required(),
                                TextInput::make('lost')->label('المفقود')->numeric()->minValue(0)->required(),
                            ])->columns(5)->addable(false)->deletable(false)->reorderable(false),
                        Textarea::make('note')->label('محضر التسوية')->required()->maxLength(2000),
                    ])
                    ->fillForm(fn (CleaningBookingMaterialKit $record): array => [
                        'lines' => $record->booking->materials()->with('material')->whereIn('inventory_status', ['reserved', 'consumed'])->get()
                            ->map(fn (CleaningBookingMaterial $line): array => [
                                'id' => $line->id, 'material' => $line->material?->name.' ('.$line->quantity.')',
                                'used' => $line->quantity, 'returned' => 0, 'damaged' => 0, 'lost' => 0,
                            ])->all(),
                    ])
                    ->action(function (CleaningBookingMaterialKit $record, array $data): void {
                        \Illuminate\Support\Facades\DB::transaction(function () use ($record, $data): void {
                            $expectedIds = $record->booking->materials()->whereIn('inventory_status', ['reserved', 'consumed'])
                                ->orderBy('id')->lockForUpdate()->pluck('id')->map(fn ($id): int => (int) $id)->all();
                            $submitted = collect($data['lines'] ?? [])->pluck('id')->map(fn ($id): int => (int) $id)->sort()->values()->all();
                            if ($submitted !== collect($expectedIds)->sort()->values()->all()) {
                                throw \Illuminate\Validation\ValidationException::withMessages(['lines' => 'Material lines changed; reopen the reconciliation form.']);
                            }
                            foreach ($data['lines'] as $line) {
                                app(CleaningMaterialInventoryService::class)->reconcileUsage(
                                    CleaningBookingMaterial::query()->findOrFail($line['id']), auth()->user(),
                                    ['used' => $line['used'], 'returned' => $line['returned'], 'damaged' => $line['damaged'], 'lost' => $line['lost']],
                                    $data['note'],
                                );
                            }
                        });
                        Notification::make()->title('تمت تسوية المواد وتحديث المخزون')->success()->send();
                    }),
            ])->defaultSort('created_at', 'desc');
    }

    public static function getPages(): array
    {
        return ['index' => ListCleaningMaterialKits::route('/'), 'edit' => EditCleaningMaterialKit::route('/{record}/edit')];
    }

    public static function canViewAny(): bool
    {
        return self::allowed('cleaning_bookings.view');
    }

    public static function canEdit(Model $record): bool
    {
        return self::allowed('cleaning_bookings.update');
    }

    public static function canCreate(): bool
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
