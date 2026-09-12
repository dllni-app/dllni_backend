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
    protected static ?int $navigationSort = 29;

    public static function getNavigationGroup(): ?string { return __('cleaning_admin.nav_groups.settings'); }
    public static function getNavigationLabel(): string { return 'Cleaning Event Types'; }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            TextInput::make('slug')->required()->maxLength(255)->unique(ignoreRecord: true),
            TextInput::make('sort_order')->numeric()->minValue(0)->default(0),
            Toggle::make('is_active')->default(true),
            Select::make('specialServices')->relationship('specialServices', 'name')->multiple()->searchable()->preload()->label('Available special services')->columnSpanFull(),
            Repeater::make('fields')->relationship()->label('Dynamic form fields')->schema([
                TextInput::make('label')->required()->maxLength(255),
                TextInput::make('key')->required()->maxLength(64),
                Select::make('field_type')->required()->options([
                    'text' => 'Text', 'textarea' => 'Long text', 'number' => 'Number',
                    'yes_no' => 'Yes / No', 'single_select' => 'Single select', 'multi_select' => 'Multi select',
                ]),
                TagsInput::make('options')->helperText('Required only for select fields.'),
                TextInput::make('sort_order')->numeric()->minValue(0)->default(0),
                Toggle::make('is_required')->default(false),
                Toggle::make('is_active')->default(true),
            ])->columns(3)->collapsible()->orderColumn('sort_order')->columnSpanFull(),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->searchable()->sortable(),
            TextColumn::make('slug')->searchable(),
            TextColumn::make('fields_count')->counts('fields')->label('Fields')->sortable(),
            TextColumn::make('special_services_count')->counts('specialServices')->label('Services')->sortable(),
            TextColumn::make('sort_order')->sortable(),
            IconColumn::make('is_active')->boolean(),
        ])->defaultSort('sort_order');
    }

    public static function getPages(): array
    {
        return ['index' => ListCleaningEventTypes::route('/'), 'create' => CreateCleaningEventType::route('/create'), 'edit' => EditCleaningEventType::route('/{record}/edit')];
    }

    public static function canViewAny(): bool { return self::allowed('pricing.view'); }
    public static function canCreate(): bool { return self::allowed('pricing.create'); }
    public static function canEdit(Model $record): bool { return self::allowed('pricing.update'); }
    public static function canDelete(Model $record): bool { return false; }
    private static function allowed(string $permission): bool { $user = auth()->user(); return $user !== null && ($user->hasAnyRole(['admin', 'Super Admin']) || $user->can($permission)); }
}
