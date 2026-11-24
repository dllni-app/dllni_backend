<?php

declare(strict_types=1);

namespace App\Filament\Resources\CleaningV3CatalogVersions\RelationManagers;

use App\Filament\Resources\CleaningV3CatalogVersions\CleaningV3CatalogVersionResource;
use Filament\Actions\CreateAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\KeyValue;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\TagsInput;
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
use Modules\Cleaning\Models\CleaningSpecialServiceEquipment;
use Modules\Cleaning\Models\CleaningV3CatalogItem;
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
                'equipment' => 'معدات تنظيف من المنصة (قابلة للإرجاع)',
                'event_type' => 'نوع مناسبة',
            ])->live(),
            Select::make('source_id')->live()->label('العنصر من الكتالوج الحالي')->required()->searchable()
                ->options(static fn (Get $get): array => self::sourceChoices((string) $get('source_type'))),
            Select::make('measurement_mode')->live()->label('وحدة القياس')->required()->options([
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
            TagsInput::make('input_rules.propertyKeys')
                ->label('المنشآت التي يظهر فيها البند (رموز الكتالوج)')
                ->helperText('اتركه فارغاً للبند المشترك. استخدم رموز بنود المنشآت الموجودة في المسودة.')
                ->dehydrateStateUsing(static fn ($state) => array_values($state ?? []))
                ->visible(static fn (Get $get): bool => $get('source_type') !== 'property')
                ->columnSpanFull(),
            TextInput::make('input_rules.splitUnitQuantity')
                ->label('أقصى كمية للمهمة الواحدة القابلة للتوزيع')
                ->helperText('اتركها فارغة إذا كان البند لا يقبل التقسيم. تطبق على الكمية وليس على السعر.')
                ->numeric()->minValue(0.01)->maxValue(9999999999)
                ->visible(static fn (Get $get): bool => $get('source_type') !== 'equipment'
                    && in_array($get('measurement_mode'), ['sqm', 'linear_meter', 'hour'], true)),
            Select::make('input_rules.equipmentAssetIds')
                ->label('معدات إضافية مماثلة في المخزون')
                ->multiple()->searchable()
                ->options(static fn (Get $get): array => self::equipmentChoices((int) $get('source_id')))
                ->helperText('اختر الأصول المتطابقة فقط. الأصل الرئيسي محسوب تلقائياً.')
                ->visible(static fn (Get $get): bool => $get('source_type') === 'equipment')
                ->columnSpanFull(),
            TextInput::make('input_rules.equipmentUnitPriceSyp')
                ->label('سعر طلب قطعة من المعدات لكل زيارة (ل.س)')
                ->numeric()->minValue(0.01)->maxValue(9999999999)
                ->required(static fn (Get $get): bool => $get('source_type') === 'equipment')
                ->visible(static fn (Get $get): bool => $get('source_type') === 'equipment'),
            KeyValue::make('input_rules.scopeLabels')
                ->visible(static fn (Get $get): bool => $get('source_type') !== 'equipment')
                ->label('أسماء نطاقات التعزيل المخصصة')
                ->keyLabel('رمز النطاق المطابق للتعرفة')
                ->valueLabel('الاسم الظاهر للعميل')
                ->helperText('النطاقات الشائعة لها أسماء عربية افتراضية. أضف اسماً لكل نطاق جديد غير معروف.')
                ->columnSpanFull(),
            Repeater::make('tariffRules')
                ->visible(static fn (Get $get): bool => $get('source_type') !== 'equipment')
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
                CreateAction::make()->label('إضافة عنصر')->mutateDataUsing(static function (array $data): array {
                    if (empty($data['input_rules']['propertyKeys'])) {
                        unset($data['input_rules']['propertyKeys']);
                    }
                    return self::normalizeItemRules($data);
                })->visible(fn (): bool => $this->editable()),
            ])
            ->recordActions([
                EditAction::make()->visible(fn (): bool => $this->editable())
                    ->using(static function (CleaningV3CatalogItem $record, array $data): void {
                        // Editing scope labels must preserve existing input constraints.
                        if (array_key_exists('input_rules', $data)) {
                            $data['input_rules'] = array_replace($record->input_rules ?? [], $data['input_rules'] ?? []);
                        }
                        if (empty($data['input_rules']['propertyKeys'])) {
                            unset($data['input_rules']['propertyKeys']);
                        }
                        $record->update(self::normalizeItemRules($data));
                    }),
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
            'equipment' => self::equipmentChoices(),
            'event_type' => CleaningEventType::query()->where('is_active', true)->pluck('name', 'id')->all(),
            default => [],
        };
    }

    private static function equipmentChoices(int $except = 0): array
    {
        return CleaningSpecialServiceEquipment::query()->where('is_active', true)
            ->when($except > 0, fn ($query) => $query->whereKeyNot($except))
            ->orderBy('name')->get()->mapWithKeys(static fn ($asset) => [
                $asset->id => $asset->name.' ('.($asset->asset_code ?? $asset->id).')',
            ])->all();
    }

    private static function normalizeItemRules(array $data): array
    {
        if (blank($data['input_rules']['splitUnitQuantity'] ?? null)
            || ! in_array($data['measurement_mode'] ?? null, ['sqm', 'linear_meter', 'hour'], true)
            || ($data['source_type'] ?? null) === 'equipment') {
            unset($data['input_rules']['splitUnitQuantity']);
        }
        if (($data['source_type'] ?? null) === 'equipment') {
            $data['measurement_mode'] = 'piece';
            foreach (['scopeLabels', 'min', 'max', 'step', 'maxSelections'] as $key) {
                unset($data['input_rules'][$key]);
            }
            $data['input_rules']['componentId'] = 'stepper';
        } else {
            unset($data['input_rules']['equipmentAssetIds'], $data['input_rules']['equipmentUnitPriceSyp']);
        }

        return $data;
    }

    private function editable(): bool
    {
        $owner = $this->getOwnerRecord();

        return $owner instanceof CleaningV3CatalogVersion
            && CleaningV3CatalogVersionResource::canEdit($owner);
    }
}
