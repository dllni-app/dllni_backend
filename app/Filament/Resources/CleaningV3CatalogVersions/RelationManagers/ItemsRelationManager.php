<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningV3CatalogVersions\RelationManagers;

use App\Filament\Resources\CleaningV3CatalogVersions\CleaningV3CatalogVersionResource;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Modules\Cleaning\Models\CleaningEventType;
use Modules\Cleaning\Models\CleaningHomeType;
use Modules\Cleaning\Models\CleaningMaterialType;
use Modules\Cleaning\Models\CleaningRoomType;
use Modules\Cleaning\Models\CleaningService;
use Modules\Cleaning\Models\CleaningSpecialService;
use Modules\Cleaning\Models\CleaningV3CatalogVersion;

final class ItemsRelationManager extends RelationManager
{
    protected static string $relationship = 'items';

    protected static ?string $title = 'عناصر الكتالوج (مسودات فقط)';

    protected static ?string $recordTitleAttribute = 'label_ar';

    public function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('item_key')->label('المعرّف الثابت')->required()->regex('/^[a-z][a-z0-9_.-]{0,99}$/')->maxLength(100),
            TextInput::make('label_ar')->label('العنوان بالعربية')->required()->maxLength(180),
            TextInput::make('label_en')->label('العنوان بالإنجليزية')->maxLength(180),
            Select::make('source_type')->label('المصدر الأصلي')->required()->options([
                'property' => 'نوع العقار',
                'room' => 'غرفة أو منطقة',
                'service' => 'خدمة',
                'special_service' => 'خدمة تخصصية',
                'material' => 'مواد مطلوبة',
                'event_type' => 'نوع مناسبة',
            ])->live(),
            Select::make('source_id')->label('العنصر من الكتالوج الحالي')->required()->searchable()
                ->options(static fn (Get $get): array => self::sourceChoices((string) $get('source_type'))),
            Select::make('measurement_mode')->label('وحدة القياس')->required()->options([
                'count' => 'عدد',
                'sqm' => 'متر مربع',
                'linear_meter' => 'متر طولي',
                'piece' => 'قطعة',
                'hour' => 'ساعة',
                'fixed' => 'ثابت (التسعير غير مفعل)',
            ]),
            Toggle::make('required')->label('إلزامي')->default(false),
            Toggle::make('is_enabled')->label('مفعّل في المسودة')->default(true),
            TextInput::make('sort_order')->label('الترتيب')->numeric()->minValue(0)->maxValue(65535)->default(0),
            Repeater::make('presets')
                ->label('نطاقات القياس (لا تتضمن أسعاراً)')
                ->relationship('presets')
                ->schema([
                    TextInput::make('preset_key')->label('رمز النطاق')->required()->regex('/^[a-z][a-z0-9_.-]{0,59}$/')->maxLength(60),
                    TextInput::make('label_ar')->label('الاسم')->required()->maxLength(150),
                    TextInput::make('label_en')->label('English')->maxLength(150),
                    TextInput::make('minimum_value')->label('من')->numeric()->minValue(0)->required(),
                    TextInput::make('maximum_value')->label('إلى')->numeric()->gt('minimum_value')->required(),
                    TextInput::make('sort_order')->label('الترتيب')->numeric()->minValue(0)->default(0),
                ])
                ->columns(3)
                ->defaultItems(0),
            Repeater::make('tariffRules')
                ->label('قواعد السعر والجهد (مسودة — غير قابلة للتحصيل)')
                ->relationship('tariffRules')
                ->schema([
                    TextInput::make('rule_key')->label('المعرّف الثابت للقاعدة')
                        ->required()->regex('/^[a-z][a-z0-9_.-]{0,99}$/')->maxLength(100),
                    Select::make('cleaning_mode')->label('نمط التنظيف')->required()->options([
                        'regular' => 'حوسة',
                        'deep' => 'تعزيل',
                    ]),
                    TextInput::make('scope_key')->label('رمز النطاق (whole أو جزء محدد)')
                        ->required()->regex('/^[a-z][a-z0-9_.-]{0,99}$/')->default('whole')->maxLength(100),
                    TextInput::make('quantity_min')->label('الكمية من')
                        ->numeric()->minValue(0)->required(),
                    TextInput::make('quantity_max')->label('الكمية إلى')
                        ->numeric()->gt('quantity_min')->required(),
                    TextInput::make('unit_price')->label('سعر الوحدة (ل.س)')
                        ->numeric()->minValue(0.01)->required(),
                    TextInput::make('unit_effort_minutes')->label('جهد الوحدة (دقيقة عمل)')
                        ->numeric()->minValue(0.01)->maxValue(480)->required(),
                    Toggle::make('is_enabled')->label('مفعّل في المسودة')->default(true),
                ])
                ->columns(3)
                ->defaultItems(0),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->recordTitleAttribute('label_ar')
            ->columns([
                TextColumn::make('item_key')->label('المعرّف'),
                TextColumn::make('label_ar')->label('العنصر'),
                TextColumn::make('source_type')->label('المصدر')->badge(),
                TextColumn::make('measurement_mode')->label('وحدة القياس'),
                TextColumn::make('presets_count')->counts('presets')->label('الأحجام'),
                TextColumn::make('tariff_rules_count')->counts('tariffRules')->label('قواعد السعر والجهد'),
            ])
            ->headerActions([
                CreateAction::make()->label('إضافة عنصر')->visible(fn (): bool => $this->editable()),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => $this->editable()),
                DeleteAction::make()->requiresConfirmation()->visible(fn (): bool => $this->editable()),
            ]);
    }

    private static function sourceChoices(string $type): array
    {
        return match ($type) {
            'property' => CleaningHomeType::query()->where('is_active', true)->pluck('title', 'id')->all(),
            'room' => CleaningRoomType::query()->where('is_active', true)->pluck('name', 'id')->all(),
            'service' => CleaningService::query()->where('is_active', true)->pluck('name', 'id')->all(),
            'special_service' => CleaningSpecialService::query()->where('is_active', true)->pluck('name', 'id')->all(),
            'material' => CleaningMaterialType::query()->where('is_active', true)->pluck('name', 'id')->all(),
            'event_type' => CleaningEventType::query()->where('is_active', true)->pluck('name', 'id')->all(),
            default => [],
        };
    }

    private function editable(): bool
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof CleaningV3CatalogVersion
            && CleaningV3CatalogVersionResource::canEdit($owner);
    }
}
