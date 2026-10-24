<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningSpecialServices;

use App\Filament\Clusters\CleaningCatalogCluster;
use App\Filament\Resources\CleaningSpecialServices\Pages\CreateCleaningSpecialService;
use App\Filament\Resources\CleaningSpecialServices\Pages\EditCleaningSpecialService;
use App\Filament\Resources\CleaningSpecialServices\Pages\ListCleaningSpecialServices;
use BackedEnum;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Modules\Cleaning\Models\CleaningSpecialService;

final class CleaningSpecialServiceResource extends Resource
{
    protected static ?string $model = CleaningSpecialService::class;

    protected static ?string $cluster = CleaningCatalogCluster::class;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 12;

    public static function getNavigationGroup(): ?string
    {
        return \App\Filament\Support\AdminNavigationGroup::cleaning();
    }

    public static function getNavigationLabel(): string
    {
        return __('cleaning_special_services.navigation');
    }

    public static function getModelLabel(): string
    {
        return __('cleaning_special_services.model');
    }

    public static function getPluralModelLabel(): string
    {
        return __('cleaning_special_services.navigation');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('cleaning_special_services.fields.name'))->required()->maxLength(255),
            Select::make('cleaning_special_service_category_id')
                ->label(__('cleaning_special_services.fields.category'))
                ->relationship('category', 'name')
                ->searchable()
                ->preload(),
            TextInput::make('description')->label(__('cleaning_special_services.fields.description'))->columnSpanFull(),
            FileUpload::make('image_path')
                ->label(__('cleaning_special_services.fields.image'))
                ->disk('public')
                ->directory('cleaning-special-services')
                ->image()
                ->imageEditor()
                ->maxSize(5120)
                ->helperText(__('cleaning_special_services.fields.image_help')),
            TextInput::make('image_url')
                ->label(__('cleaning_special_services.fields.fallback_image_url'))
                ->url()
                ->maxLength(2048)
                ->helperText(__('cleaning_special_services.fields.fallback_image_help')),
            Select::make('pricing_unit')->label(__('cleaning_special_services.fields.pricing_unit'))->required()->options([
                'piece' => __('cleaning_special_services.pricing_units.piece'),
                'sqm' => __('cleaning_special_services.pricing_units.sqm'),
                'linear_meter' => __('cleaning_special_services.pricing_units.linear_meter'),
                'device' => __('cleaning_special_services.pricing_units.device'),
                'sofa' => __('cleaning_special_services.pricing_units.sofa'),
                'chair' => __('cleaning_special_services.pricing_units.chair'),
                'carpet' => __('cleaning_special_services.pricing_units.carpet'),
                'solar_panel' => __('cleaning_special_services.pricing_units.solar_panel'),
            ]),
            Select::make('input_type')->label(__('cleaning_special_services.fields.input_type'))->required()->options([
                'quantity' => __('cleaning_special_services.input_types.quantity'),
                'decimal' => __('cleaning_special_services.input_types.decimal'),
                'area' => __('cleaning_special_services.input_types.area'),
                'length' => __('cleaning_special_services.input_types.length'),
            ])->default('quantity'),
            TextInput::make('unit_code')->label(__('cleaning_special_services.fields.unit_code'))->maxLength(32),
            TextInput::make('base_unit_price')->label(__('cleaning_special_services.fields.base_unit_price'))->numeric()->minValue(0)->required(),
            Toggle::make('supports_dirtiness')->label(__('cleaning_special_services.fields.supports_dirtiness'))->default(true),
            Select::make('dirtinessLevels')
                ->relationship('dirtinessLevels', 'name')
                ->multiple()
                ->searchable()
                ->preload()
                ->label(__('cleaning_special_services.fields.dirtiness_levels')),
            Select::make('gender_constraint')->label(__('cleaning_special_services.fields.gender_constraint'))->options([
                'male' => __('cleaning_special_services.gender_constraints.male'),
                'female' => __('cleaning_special_services.gender_constraints.female'),
            ])->nullable(),
            TextInput::make('estimated_duration_minutes')->label(__('cleaning_special_services.fields.duration'))->numeric()->minValue(1)->required()->default(60)->suffix(__('cleaning_special_services.fields.duration_short')),
            Select::make('worker_pay_mode')->label(__('cleaning_special_services.fields.worker_pay_mode'))->required()->options([
                'flat' => __('cleaning_special_services.pay_modes.flat'),
                'percentage' => __('cleaning_special_services.pay_modes.percentage'),
                'per_unit' => __('cleaning_special_services.pay_modes.per_unit'),
            ])->default('percentage'),
            TextInput::make('worker_pay_value')->label(__('cleaning_special_services.fields.worker_pay_value'))->numeric()->minValue(0)->required()->default(0),
            Select::make('operating_cost_mode')->label(__('cleaning_special_services.fields.operating_cost_mode'))->required()->options([
                'flat' => __('cleaning_special_services.pay_modes.flat'),
                'percentage' => __('cleaning_special_services.pay_modes.percentage'),
                'per_unit' => __('cleaning_special_services.pay_modes.per_unit'),
            ])->default('flat'),
            TextInput::make('operating_cost_value')->label(__('cleaning_special_services.fields.operating_cost_value'))->numeric()->minValue(0)->required()->default(0),
            Select::make('travel_fee_mode')->label(__('cleaning_special_services.fields.travel_fee_mode'))->required()->options([
                'flat' => __('cleaning_special_services.pay_modes.flat'),
                'percentage' => __('cleaning_special_services.pay_modes.percentage'),
                'per_km' => __('cleaning_special_services.pay_modes.per_km'),
            ])->default('flat'),
            TextInput::make('travel_fee_value')->label(__('cleaning_special_services.fields.travel_fee_value'))->numeric()->minValue(0)->required()->default(0),
            Toggle::make('requires_before_image')->label(__('cleaning_special_services.fields.requires_before_image'))->default(false),
            Toggle::make('requires_after_image')->label(__('cleaning_special_services.fields.requires_after_image'))->default(false),
            Toggle::make('is_active')->label(__('cleaning_special_services.fields.is_active'))->default(true),
            Select::make('equipment')
                ->relationship('equipment', 'name')
                ->multiple()
                ->searchable()
                ->preload()
                ->label(__('cleaning_special_services.fields.equipment')),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->label(__('cleaning_special_services.fields.name'))->searchable()->sortable(),
                TextColumn::make('pricing_unit')->label(__('cleaning_special_services.fields.pricing_unit'))->badge()->formatStateUsing(fn (string $state): string => __('cleaning_special_services.pricing_units.'.$state))->sortable(),
                TextColumn::make('category.name')->label(__('cleaning_special_services.fields.category'))->placeholder('—')->sortable(),
                TextColumn::make('estimated_duration_minutes')->label(__('cleaning_special_services.fields.duration'))->suffix(' '.__('cleaning_special_services.fields.duration_short'))->sortable(),
                TextColumn::make('base_unit_price')->label(__('cleaning_special_services.fields.base_unit_price'))->money(config('app.currency', 'SYP'))->sortable(),
                TextColumn::make('equipment.name')
                    ->label(__('cleaning_special_services.fields.equipment'))
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->toggleable(),
                IconColumn::make('is_active')->label(__('cleaning_special_services.fields.is_active'))->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')->label(__('cleaning_special_services.fields.is_active'))])
            ->defaultSort('name');
    }

    public static function getPages(): array
    {
        return [
            'index' => ListCleaningSpecialServices::route('/'),
            'create' => CreateCleaningSpecialService::route('/create'),
            'edit' => EditCleaningSpecialService::route('/{record}/edit'),
        ];
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

        return $user !== null
            && ($user->hasAnyRole(['admin', 'Super Admin']) || $user->can($permission));
    }
}
