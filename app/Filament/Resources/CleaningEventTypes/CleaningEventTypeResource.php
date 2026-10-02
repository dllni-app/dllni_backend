<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningEventTypes;

use App\Filament\Resources\CleaningEventTypes\Pages\CreateCleaningEventType;
use App\Filament\Resources\CleaningEventTypes\Pages\EditCleaningEventType;
use App\Filament\Resources\CleaningEventTypes\Pages\ListCleaningEventTypes;
use BackedEnum;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningEventType;

final class CleaningEventTypeResource extends Resource
{
    protected static ?string $model = CleaningEventType::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCalendarDays;

    protected static ?int $navigationSort = 25;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::cleaning();
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_catalog.materials.event_types');
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_catalog.materials.singulars.event_type');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_catalog.materials.event_types');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('cleaning_catalog.materials.fields.name'))->required()->maxLength(255),
            TextInput::make('slug')->label(__('cleaning_catalog.materials.fields.slug'))->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('sort_order')->label(__('cleaning_catalog.materials.fields.sort_order'))->numeric()->minValue(0)->default(0),
            Toggle::make('is_active')->label(__('cleaning_catalog.materials.fields.is_active'))->default(true),
            Select::make('specialServices')->relationship('specialServices', 'name')->multiple()->searchable()->preload()->label(__('cleaning_catalog.materials.fields.available_services'))->columnSpanFull(),
            Repeater::make('fields')->relationship()->label(__('cleaning_catalog.materials.fields.dynamic_fields'))->schema([
                TextInput::make('label')->label(__('cleaning_catalog.materials.fields.field_label'))->required()->maxLength(255),
                TextInput::make('key')->label(__('cleaning_catalog.materials.fields.field_key'))->required()->maxLength(64),
                Select::make('field_type')->label(__('cleaning_catalog.materials.fields.field_type'))->required()->options([
                    'text' => __('cleaning_catalog.materials.options.text'), 'textarea' => __('cleaning_catalog.materials.options.textarea'), 'number' => __('cleaning_catalog.materials.options.number'),
                    'yes_no' => __('cleaning_catalog.materials.options.yes_no'), 'single_select' => __('cleaning_catalog.materials.options.single_select'), 'multi_select' => __('cleaning_catalog.materials.options.multi_select'),
                ]),
                TagsInput::make('options')->label(__('cleaning_catalog.materials.fields.options'))->helperText(__('cleaning_catalog.materials.fields.options_help')),
                TextInput::make('sort_order')->label(__('cleaning_catalog.materials.fields.sort_order'))->numeric()->minValue(0)->default(0),
                Toggle::make('is_required')->label(__('cleaning_catalog.materials.fields.is_required'))->default(false),
                Toggle::make('is_active')->label(__('cleaning_catalog.materials.fields.is_active'))->default(true),
            ])->columns(3)->collapsible()->orderColumn('sort_order')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('cleaning_catalog.materials.fields.name'))->searchable()->sortable(),
            TextColumn::make('slug')->label(__('cleaning_catalog.materials.fields.slug'))->searchable(),
            TextColumn::make('fields_count')->counts('fields')->label(__('cleaning_catalog.materials.fields.field_count'))->sortable(),
            TextColumn::make('special_services_count')->counts('specialServices')->label(__('cleaning_catalog.materials.fields.services'))->sortable(),
            TextColumn::make('sort_order')->label(__('cleaning_catalog.materials.fields.sort_order'))->sortable(),
            IconColumn::make('is_active')->label(__('cleaning_catalog.materials.fields.is_active'))->boolean(),
        ])->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return ['index' => ListCleaningEventTypes::route('/'), 'create' => CreateCleaningEventType::route('/create'), 'edit' => EditCleaningEventType::route('/{record}/edit')];
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
        return false;
    }

    private static function allowed(string $permission): bool
    {
        $user = auth()->user();

        return $user !== null && ($user->hasAnyRole(['admin', 'Super Admin']) || $user->can($permission));
    }
}
