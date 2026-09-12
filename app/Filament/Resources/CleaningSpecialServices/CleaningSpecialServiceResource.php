<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningSpecialServices;

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

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSparkles;

    protected static ?int $navigationSort = 23;

    public static function getNavigationGroup(): ?string
    {
        return __('cleaning_admin.nav_groups.operations');
    }

    public static function getNavigationLabel(): string
    {
        return 'Special Cleaning Services';
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->required()->maxLength(255),
            Select::make('cleaning_special_service_category_id')
                ->label('Category')
                ->relationship('category', 'name')
                ->searchable()
                ->preload(),
            TextInput::make('description')->columnSpanFull(),
            FileUpload::make('image_path')
                ->label(app()->isLocale('ar') ? 'صورة الخدمة' : 'Service Image')
                ->disk('public')
                ->directory('cleaning-special-services')
                ->image()
                ->imageEditor()
                ->maxSize(5120)
                ->helperText(app()->isLocale('ar')
                    ? 'ارفع صورة واضحة للخدمة لا يتجاوز حجمها 5 ميغابايت.'
                    : 'Upload a clear service image up to 5 MB.'),
            TextInput::make('image_url')
                ->label(app()->isLocale('ar') ? 'رابط صورة خارجي احتياطي' : 'Fallback External Image URL')
                ->url()
                ->maxLength(2048)
                ->helperText(app()->isLocale('ar')
                    ? 'اختياري للتوافق مع الصور القديمة. الصورة المرفوعة لها الأولوية.'
                    : 'Optional legacy fallback. An uploaded image takes precedence.'),
            Select::make('pricing_unit')->required()->options([
                'piece' => 'Piece',
                'sqm' => 'Square meter',
                'linear_meter' => 'Linear meter',
                'device' => 'Device',
                'sofa' => 'Sofa',
                'chair' => 'Chair',
                'carpet' => 'Carpet',
                'solar_panel' => 'Solar panel',
            ]),
            Select::make('input_type')->required()->options([
                'quantity' => 'Quantity',
                'decimal' => 'Decimal measurement',
                'area' => 'Area',
                'length' => 'Length',
            ])->default('quantity'),
            TextInput::make('unit_code')->maxLength(32),
            TextInput::make('base_unit_price')->numeric()->minValue(0)->required(),
            Toggle::make('supports_dirtiness')->default(true),
            Select::make('dirtinessLevels')
                ->relationship('dirtinessLevels', 'name')
                ->multiple()
                ->searchable()
                ->preload()
                ->label('Allowed dirtiness levels'),
            Select::make('gender_constraint')->options([
                'male' => 'Male worker',
                'female' => 'Female worker',
            ])->nullable(),
            TextInput::make('estimated_duration_minutes')->numeric()->minValue(1)->required()->default(60)->suffix('min'),
            Select::make('worker_pay_mode')->required()->options(['flat'=>'Flat','percentage'=>'Percentage','per_unit'=>'Per unit'])->default('percentage'),
            TextInput::make('worker_pay_value')->numeric()->minValue(0)->required()->default(0),
            Select::make('operating_cost_mode')->required()->options(['flat'=>'Flat','percentage'=>'Percentage','per_unit'=>'Per unit'])->default('flat'),
            TextInput::make('operating_cost_value')->numeric()->minValue(0)->required()->default(0),
            Select::make('travel_fee_mode')->required()->options(['flat'=>'Flat','percentage'=>'Percentage','per_km'=>'Per km'])->default('flat'),
            TextInput::make('travel_fee_value')->numeric()->minValue(0)->required()->default(0),
            Toggle::make('requires_before_image')->label('Require before image')->default(false),
            Toggle::make('requires_after_image')->label('Require after image')->default(false),
            Toggle::make('is_active')->default(true),
            Select::make('equipment')
                ->relationship('equipment', 'name')
                ->multiple()
                ->searchable()
                ->preload()
                ->label('Required Equipment'),
        ])->columns(2);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('pricing_unit')->badge()->sortable(),
                TextColumn::make('category.name')->label('Category')->placeholder('—')->sortable(),
                TextColumn::make('estimated_duration_minutes')->label('Duration')->suffix(' min')->sortable(),
                TextColumn::make('base_unit_price')->money(config('app.currency', 'SYP'))->sortable(),
                TextColumn::make('equipment.name')
                    ->label('Equipment')
                    ->listWithLineBreaks()
                    ->limitList(3)
                    ->toggleable(),
                IconColumn::make('is_active')->boolean(),
            ])
            ->filters([TernaryFilter::make('is_active')])
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
